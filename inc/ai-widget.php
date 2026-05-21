<?php
/**
 * gend-society — AI chat widget loader (subsite side).
 *
 * Loads LEO's frontend chat widget on subsites WITHOUT LEO installed
 * locally. The widget's JS assets are pulled from the gend.me hub (single
 * canonical copy — no fork), and every REST call the widget makes is
 * pointed at the LOCAL aipa/v1 namespace, which GS_AI_Proxy's catch-all
 * forwarder relays to the hub server-side with the member's OAuth bearer.
 * The bearer therefore never reaches browser JS.
 *
 * DORMANT WHILE LEO IS ACTIVE: if the LEO plugin is present on this site
 * (its widget enqueuer exists), this loader does nothing — LEO keeps
 * owning the widget. It only takes over once LEO is deactivated on the
 * subsite, so deploying this changes nothing until that cutover.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GS_AI_Widget {

    public static function init() {
        // Stand down entirely when LEO is loaded on this site — LEO owns
        // the widget then (this is the hub, where LEO is network-active and
        // not filtered out). On subsites a mu-plugin removes LEO, so this
        // function check is false and gend-society serves the widget.
        // Runs on plugins_loaded so LEO's includes have all run by now.
        if ( function_exists( 'aipa_widget_register_scripts' ) || class_exists( 'AIPA_REST_AI_Proxy' ) ) {
            return;
        }

        add_action( 'wp_enqueue_scripts',          array( __CLASS__, 'enqueue' ), 5 );
        add_action( 'admin_enqueue_scripts',        array( __CLASS__, 'enqueue' ), 5 );
        add_action( 'enqueue_block_editor_assets',  array( __CLASS__, 'enqueue' ), 5 );
        add_action( 'wp_footer',    array( __CLASS__, 'render_footer' ), 99999 );
        add_action( 'admin_footer', array( __CLASS__, 'render_footer' ), 99999 );

        add_shortcode( 'aipa_wireframe',  array( __CLASS__, 'shortcode_wireframe' ) );
        add_shortcode( 'aipa_media_chat', array( __CLASS__, 'shortcode_media_chat' ) );
    }

    /** Hub base (portal-stored, filterable). */
    protected static function hub_base() {
        $base = get_option( 'gs_gend_base_url' );
        if ( ! $base ) {
            $base = 'https://gend.me';
        }
        return untrailingslashit( apply_filters( 'gs_ai_hub_base', $base ) );
    }

    /** Pinned widget asset version on the hub (filterable for upgrades). */
    protected static function widget_js_file() {
        return apply_filters( 'gs_ai_widget_js_file', 'widget-app-v1.9.82.js' );
    }

    /** Only members who connected their gend.me account can use AI. */
    protected static function eligible() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        if ( is_admin() && ! current_user_can( 'edit_posts' ) ) {
            return false;
        }
        return class_exists( 'Gend_CP_OAuth_Client' )
            && method_exists( 'Gend_CP_OAuth_Client', 'is_connected' )
            && Gend_CP_OAuth_Client::is_connected( get_current_user_id() );
    }

    public static function enqueue() {
        if ( ! self::eligible() ) {
            return;
        }
        if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || wp_is_json_request() ) {
            return;
        }

        $hub  = self::hub_base();
        $leo  = $hub . '/wp-content/plugins/leo/assets/js/';
        $ver  = '2.1.0';

        // Hub-hosted canonical assets (cross-origin <script src> is fine).
        wp_enqueue_script( 'aipa-widget', $leo . self::widget_js_file(), array(), $ver, true );
        wp_enqueue_script( 'aipa-widget-leo-flows', $leo . 'widget-leo-flows.js', array( 'aipa-widget' ), $ver, true );
        wp_enqueue_script( 'leo-flow-engine', $leo . 'leo-flow-engine.js', array( 'aipa-widget', 'aipa-widget-leo-flows' ), $ver, true );

        // Config — REST base points at the LOCAL aipa/v1 forwarder, so the
        // widget never talks to the hub directly and never sees the bearer.
        $config = self::build_config();
        $json   = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        wp_add_inline_script( 'aipa-widget', 'window.AIPA_WIDGET = ' . $json . '; window.leoData = window.AIPA_WIDGET;', 'before' );
    }

    protected static function build_config() {
        $uid = get_current_user_id();
        return apply_filters( 'aipa_widget_config', array(
            // Local namespace — forwarded to the hub by GS_AI_Proxy.
            'rest'              => esc_url_raw( get_rest_url( null, 'aipa/v1' ) ),
            'rest_url'          => trailingslashit( esc_url_raw( get_rest_url( get_current_blog_id() ) ) ),
            'rest_assistant'    => esc_url_raw( get_rest_url( null, 'aipa/v1' ) ),
            'restBase'          => trailingslashit( esc_url_raw( get_rest_url( get_current_blog_id() ) ) ),
            'nonce'             => wp_create_nonce( 'wp_rest' ),
            'model'             => 'gemini-2.0-flash',
            'default_model'     => 'gemini-2.0-flash',
            // Server-side proxy model: the widget calls LOCAL rest; the hub
            // is reached by the forwarder, not the browser. So hub mode off
            // and no token in JS.
            'centralHubEnabled' => false,
            'oauthToken'        => '',
            'isGendConnected'   => true,
            'icon'              => 'https://gend.me/wp-content/uploads/2025/12/Futuristic_Logo_Animation_Generation-ezgif.com-crop-1.gif',
            'leo_avatar'        => 'https://gend.me/wp-content/uploads/2025/12/Animated_Profile_Picture_At_Desk-ezgif.com-optimize.gif',
            'user_avatar'       => self::user_avatar_url(),
            'version'           => '2.1.0',
            'siteName'          => get_bloginfo( 'name' ),
            'tagline'           => get_bloginfo( 'description' ),
            'logoId'            => (int) get_theme_mod( 'custom_logo' ),
            'faviconId'         => (int) get_option( 'site_icon' ),
            'brandSettings'     => get_option( 'aipa_brand_settings', array() ),
            'siteUrl'           => wp_parse_url( home_url(), PHP_URL_PATH ) ?: '/',
            'adminUrl'          => admin_url(),
            'isLoggedIn'        => true,
            'currentUserId'     => $uid,
            'isSuperAdmin'      => is_super_admin(),
            'isAdmin'           => current_user_can( 'manage_options' ),
            'currentUserEmail'  => wp_get_current_user()->user_email,
            'user'              => array(
                'display_name' => wp_get_current_user()->display_name,
                'email'        => wp_get_current_user()->user_email,
            ),
            'initialBalance'    => 0, // widget refreshes via /ai-proxy/balance
            'tokenCurrency'     => 'USD',
            'site_name'         => get_bloginfo( 'name' ),
            'site_url'          => home_url(),
        ) );
    }

    protected static function user_avatar_url() {
        $uid = get_current_user_id();
        if ( ! $uid ) {
            return '';
        }
        if ( function_exists( 'bp_core_fetch_avatar' ) ) {
            return bp_core_fetch_avatar( array(
                'item_id' => $uid, 'object' => 'user', 'type' => 'thumb',
                'html' => false, 'width' => 48, 'height' => 48,
            ) );
        }
        return get_avatar_url( $uid, array( 'size' => 48 ) );
    }

    public static function render_footer() {
        if ( ! self::eligible() ) {
            return;
        }
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;
        echo "\n<!-- GS AI WIDGET START -->\n";
        echo '<aipa-widget></aipa-widget>';
        echo "\n<!-- GS AI WIDGET END -->\n";
    }

    public static function shortcode_wireframe( $atts ) {
        if ( ! self::eligible() ) {
            return '';
        }
        self::enqueue();
        $atts = shortcode_atts( array( 'height' => '90vh', 'width' => '100%' ), $atts, 'aipa_wireframe' );
        return sprintf(
            '<div id="aipa-wireframe-embed" data-autostart="wireframe" data-placement="embedded" style="width:%s; height:%s; min-height:90vh; position:relative; display:flex; flex-direction:column;"></div>',
            esc_attr( $atts['width'] ),
            esc_attr( $atts['height'] )
        );
    }

    public static function shortcode_media_chat( $atts ) {
        if ( ! self::eligible() ) {
            return '';
        }
        self::enqueue();
        $atts = shortcode_atts( array( 'height' => '600px', 'width' => '100%' ), $atts, 'aipa_media_chat' );
        return sprintf(
            '<div id="aipa-media-chat-embed" style="width:%s; height:%s; position:relative;"></div>',
            esc_attr( $atts['width'] ),
            esc_attr( $atts['height'] )
        );
    }
}

// Defer to plugins_loaded so LEO's presence is reliably known before we
// decide whether to register the widget (LEO requires its widget.php at
// include time, so the function exists by plugins_loaded).
add_action( 'plugins_loaded', array( 'GS_AI_Widget', 'init' ), 20 );
