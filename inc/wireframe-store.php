<?php
/**
 * gend-society — Wireframe artifact store (subsite side).
 *
 * The [aipa_wireframe] shortcode used to be ephemeral — every visit
 * re-launched the chatflow because the rendered HTML lived only in the
 * widget's in-browser context. This store persists the wireframe so the
 * shortcode shows the saved version on subsequent visits, and admins can
 * Replace or Add On instead of starting from scratch.
 *
 *   Local canonical store (this subsite):
 *     wp_option  aipa_wireframe_html   full <!DOCTYPE html> document
 *     wp_option  aipa_wireframe_meta   { page, brand_dna, style_direction,
 *                                        animation_level, generated_at,
 *                                        last_updated, generation_count, pages[] }
 *
 *   Hub mirror (read by the Business Plan group's Wireframe sub-tab):
 *     groups_groupmeta  _gdc_wireframe_html / _gdc_wireframe_meta
 *     written fire-and-forget by /save and /clear via the user's OAuth
 *     bearer → POST /wp-json/gdc-app-manager/v1/me/memberships/<id>/wireframe-html
 *     on the hub (see Gdc_Brain::handle_wireframe_html).
 *
 * Routes registered under the gs/v1 namespace (the same namespace gend-society
 * uses for its other subsite-only handlers like /gs/v1/ai/chat).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register gend-society's own `chatflows/` directory as a seed source for
 * LEO's chatflow DB. The architecture moves customer-facing chatflows
 * (wireframe, etc.) out of LEO and into gend-society — LEO is hub-only,
 * and seeds owned by the plugin that hosts them keeps the chatflow files
 * deployable in the same package as the code that uses them.
 *
 * The filter is provided by Leo_DB::chatflow_seed_dirs(). The seeder
 * upserts by slug, so an existing `wireframe` row in wp_leo_chatflows is
 * version-compared and updated in place — no data loss when the seed file
 * moves between plugins.
 */
add_filter( 'leo_chatflow_seed_dirs', function ( $dirs ) {
    $dir = GS_DIR . 'chatflows/';
    if ( is_dir( $dir ) ) {
        $dirs[] = $dir;
    }
    return $dirs;
} );

class GS_Wireframe_Store {

    const OPT_HTML = 'aipa_wireframe_html';
    const OPT_META = 'aipa_wireframe_meta';
    const NS       = 'gs/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( self::NS, '/wireframe/save', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'route_save' ),
            'permission_callback' => array( __CLASS__, 'can_edit' ),
        ) );

        register_rest_route( self::NS, '/wireframe/clear', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'route_clear' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );

        register_rest_route( self::NS, '/wireframe', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'route_get' ),
            'permission_callback' => '__return_true', // public read — shortcode renders to all visitors
        ) );
    }

    public static function can_edit() {
        return current_user_can( 'edit_posts' )
            ? true
            : new WP_Error( 'gs_wf_forbidden', 'Editor capability required.', array( 'status' => 403 ) );
    }

    public static function can_manage() {
        return current_user_can( 'manage_options' )
            ? true
            : new WP_Error( 'gs_wf_forbidden', 'Administrator capability required.', array( 'status' => 403 ) );
    }

    /**
     * POST /gs/v1/wireframe/save
     *   body: { html: string, meta?: { page, brand_dna, style_direction,
     *           animation_level } }
     *
     * Writes the local canonical option then fires a non-blocking mirror to
     * the hub. Returns immediately on success so the chatflow UI doesn't
     * stall waiting for the cross-site mirror — a failed mirror leaves the
     * subsite as the source of truth, and the next save will retry.
     */
    public static function route_save( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) $body = json_decode( $request->get_body(), true );
        if ( ! is_array( $body ) ) $body = array();

        $html = isset( $body['html'] ) ? (string) $body['html'] : '';
        if ( $html === '' ) {
            return new WP_REST_Response( array( 'error' => 'html_required' ), 400 );
        }
        if ( strlen( $html ) > 1024 * 1024 ) {
            $html = substr( $html, 0, 1024 * 1024 );
        }

        $incoming = isset( $body['meta'] ) && is_array( $body['meta'] ) ? $body['meta'] : array();
        $existing = self::get_meta();
        $now      = gmdate( 'c' );

        $meta = array(
            'page'             => isset( $incoming['page'] )             ? sanitize_text_field( (string) $incoming['page'] )             : ( $existing['page'] ?? '' ),
            'brand_dna'        => isset( $incoming['brand_dna'] )        ? self::sanitize_brand_dna( $incoming['brand_dna'] )            : ( $existing['brand_dna'] ?? array() ),
            'style_direction'  => isset( $incoming['style_direction'] )  ? sanitize_text_field( (string) $incoming['style_direction'] )  : ( $existing['style_direction'] ?? '' ),
            'animation_level'  => isset( $incoming['animation_level'] )  ? sanitize_text_field( (string) $incoming['animation_level'] )  : ( $existing['animation_level'] ?? '' ),
            'generated_at'     => $existing['generated_at'] ?? $now,
            'last_updated'     => $now,
            'generation_count' => (int) ( $existing['generation_count'] ?? 0 ) + 1,
        );

        update_option( self::OPT_HTML, $html, false );
        update_option( self::OPT_META, $meta, false );

        self::mirror_to_hub( $html, $meta );

        return new WP_REST_Response( array(
            'success'      => true,
            'bytes'        => strlen( $html ),
            'last_updated' => $meta['last_updated'],
            'count'        => $meta['generation_count'],
        ), 200 );
    }

    /**
     * POST /gs/v1/wireframe/clear
     *
     * Admin-only — used by the "Regenerate (Replace)" button before
     * re-launching the chatflow so the next page load doesn't render the
     * stale wireframe. Mirrors a clear to the hub too.
     */
    public static function route_clear( WP_REST_Request $request ) {
        delete_option( self::OPT_HTML );
        delete_option( self::OPT_META );
        self::mirror_to_hub( '', array( 'cleared_at' => gmdate( 'c' ) ) );
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * GET /gs/v1/wireframe
     *
     * Returns the saved wireframe + meta. Public so the widget mounted in
     * other contexts (Add-On mode pre-loading the existing HTML, future
     * external embeds) can read it without needing a bearer.
     */
    public static function route_get( WP_REST_Request $request ) {
        return new WP_REST_Response( array(
            'html' => (string) get_option( self::OPT_HTML, '' ),
            'meta' => self::get_meta(),
        ), 200 );
    }

    public static function get_html() {
        return (string) get_option( self::OPT_HTML, '' );
    }

    public static function get_meta() {
        $m = get_option( self::OPT_META, array() );
        return is_array( $m ) ? $m : array();
    }

    /**
     * Fire-and-forget mirror to the hub. Pulls the user's OAuth bearer via
     * the same Gend_CP_OAuth_Client the AI proxy uses, then POSTs to the
     * hub's wireframe-html endpoint. Empty `$html` signals a clear.
     *
     * Membership ID resolves locally first (wu_get_current_site is the
     * canonical mapping on path-based multisite); falls back to a generic
     * "me" sentinel which the hub endpoint resolves from the bearer.
     */
    protected static function mirror_to_hub( $html, $meta ) {
        $uid = get_current_user_id();
        if ( ! $uid ) return;

        $bearer = '';
        if ( class_exists( 'Gend_CP_OAuth_Client' ) && method_exists( 'Gend_CP_OAuth_Client', 'bearer_for' ) ) {
            $bearer = (string) Gend_CP_OAuth_Client::bearer_for( $uid );
        }
        if ( $bearer === '' ) return;

        $membership_id = self::resolve_membership_id();
        if ( $membership_id === '' ) return;

        $hub = (string) get_option( 'gs_gend_base_url', 'https://gend.me' );
        $hub = untrailingslashit( $hub );
        $url = $hub . '/wp-json/gdc-app-manager/v1/me/memberships/' . rawurlencode( $membership_id ) . '/wireframe-html';

        $payload = array(
            'html' => (string) $html,
            'meta' => is_array( $meta ) ? $meta : array(),
        );

        wp_remote_post( $url, array(
            'timeout'  => 15,
            'blocking' => false, // fire-and-forget; subsite is canonical
            'headers'  => array(
                'Authorization' => 'Bearer ' . $bearer,
                'X-Gend-Token'  => $bearer,
                'Content-Type'  => 'application/json',
            ),
            'body'     => wp_json_encode( $payload ),
        ) );
    }

    /**
     * The WP Ultimo membership ID for the current site. On path-based
     * multisite the per-site → membership mapping is queryable from any
     * subsite. Returns '' when WP Ultimo isn't active.
     */
    protected static function resolve_membership_id() {
        if ( function_exists( 'wu_get_current_site' ) ) {
            $s = wu_get_current_site();
            if ( $s && method_exists( $s, 'get_membership_id' ) ) {
                $mid = (int) $s->get_membership_id();
                if ( $mid > 0 ) return (string) $mid;
            }
        }
        return '';
    }

    /**
     * Keep the brand_dna blob shaped like what the chatflow produces.
     * Allows the colors/typography sub-objects to pass through but
     * sanitizes their string values.
     */
    protected static function sanitize_brand_dna( $dna ) {
        if ( ! is_array( $dna ) ) return array();
        $out = array();
        if ( isset( $dna['colors'] ) && is_array( $dna['colors'] ) ) {
            $out['colors'] = array_map( 'sanitize_text_field', array_map( 'strval', $dna['colors'] ) );
        }
        if ( isset( $dna['typography'] ) && is_array( $dna['typography'] ) ) {
            $typ = $dna['typography'];
            if ( isset( $typ['elements'] ) && is_array( $typ['elements'] ) ) {
                $typ['elements'] = array_map( 'sanitize_text_field', array_map( 'strval', $typ['elements'] ) );
            }
            $out['typography'] = $typ;
        }
        return $out;
    }
}

GS_Wireframe_Store::init();
