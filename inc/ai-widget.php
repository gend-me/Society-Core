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
            // Server-side proxy model: the widget calls LOCAL rest for chat;
            // the hub is reached by the forwarder, not the browser. So hub
            // mode off and no token in JS.
            'centralHubEnabled' => false,
            'oauthToken'        => '',
            'isGendConnected'   => true,
            // OAuth login config — REQUIRED so the widget's "Sign in with
            // gend.me" popup uses the SAME client the hub exchanges with
            // (gend-society + LEO share this client id). Without these the
            // authorize step had no client_id and the forwarded exchange on
            // the hub failed with "code invalid for the client". The
            // exchange itself is forwarded to the hub via the aipa/v1
            // catch-all (POST /aipa/v1/oauth/exchange).
            'oauthClientId'     => function_exists( 'gs_oauth_client_id' ) ? gs_oauth_client_id() : '',
            'centralHubUrl'     => self::hub_base(),
            'oauthClientID'     => function_exists( 'gs_oauth_client_id' ) ? gs_oauth_client_id() : '', // alt-case alias some widget builds read
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
        $atts = shortcode_atts( array( 'height' => '90vh', 'width' => '100%' ), $atts, 'aipa_wireframe' );

        // Regenerate-mode entry: an admin clicked Replace / Add Section /
        // Add Page from the saved toolbar. Render the embed container with
        // entry-step hints so the engine jumps into the right chatflow step
        // (replace clears the saved option first; add-section/add-page
        // pre-load context.wireframe_html via GET /gs/v1/wireframe).
        $mode = isset( $_GET['wireframe_mode'] ) ? sanitize_key( wp_unslash( $_GET['wireframe_mode'] ) ) : '';
        $valid_modes = array( 'replace' => 'intro', 'add-section' => 'add_section_prompt', 'add-page' => 'page_brief' );
        if ( $mode && isset( $valid_modes[ $mode ] ) && current_user_can( 'manage_options' ) ) {
            if ( $mode === 'replace' ) {
                delete_option( 'aipa_wireframe_html' );
                delete_option( 'aipa_wireframe_meta' );
            }
            if ( ! self::eligible() ) return '';
            self::enqueue();
            return sprintf(
                '<div id="aipa-wireframe-embed" data-autostart="wireframe" data-placement="embedded" data-entry-step="%s" data-preload="%s" style="width:%s; height:%s; min-height:90vh; position:relative; display:flex; flex-direction:column;"></div>',
                esc_attr( $valid_modes[ $mode ] ),
                $mode === 'replace' ? '0' : '1',
                esc_attr( $atts['width'] ),
                esc_attr( $atts['height'] )
            );
        }

        // Saved-render path: a wireframe already exists. Show it in a
        // sandboxed iframe and (for admins) layer in the regenerate toolbar.
        $saved_html = class_exists( 'GS_Wireframe_Store' )
            ? GS_Wireframe_Store::get_html()
            : (string) get_option( 'aipa_wireframe_html', '' );

        if ( $saved_html !== '' ) {
            return self::render_saved_wireframe( $saved_html, $atts );
        }

        // First-run path: kick off the chatflow in the embed container.
        if ( ! self::eligible() ) {
            return '';
        }
        self::enqueue();
        return sprintf(
            '<div id="aipa-wireframe-embed" data-autostart="wireframe" data-placement="embedded" style="width:%s; height:%s; min-height:90vh; position:relative; display:flex; flex-direction:column;"></div>',
            esc_attr( $atts['width'] ),
            esc_attr( $atts['height'] )
        );
    }

    /**
     * Render the saved wireframe in a sandboxed iframe. Visitors see just
     * the iframe; site admins also see a small toolbar with Replace and
     * Add On (which expands to "Add a section" / "Add a new page").
     *
     * The iframe uses srcdoc so the wireframe document is self-contained
     * and origin-isolated — its Tailwind CDN script, Google Fonts, and
     * IntersectionObserver animations all run inside the sandbox without
     * leaking into the host page's DOM.
     */
    protected static function render_saved_wireframe( $html, $atts ) {
        $is_admin    = current_user_can( 'manage_options' );
        $page_url    = remove_query_arg( 'wireframe_mode' );
        $replace_url = add_query_arg( 'wireframe_mode', 'replace',     $page_url );
        $section_url = add_query_arg( 'wireframe_mode', 'add-section', $page_url );
        $newpage_url = add_query_arg( 'wireframe_mode', 'add-page',    $page_url );
        // srcdoc requires the document HTML escaped for an HTML attribute —
        // the double-quote escape is the security boundary that prevents
        // the wireframe from breaking out of the iframe attribute.
        $srcdoc      = esc_attr( $html );

        ob_start();
        ?>
        <div class="aipa-wireframe-saved" style="position:relative; width:<?php echo esc_attr( $atts['width'] ); ?>; min-height:<?php echo esc_attr( $atts['height'] ); ?>; display:flex; flex-direction:column;">
            <?php if ( $is_admin ) : ?>
                <div class="aipa-wireframe-toolbar" style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 14px; background:rgba(15,23,42,0.92); color:#e2e8f0; border-bottom:1px solid rgba(255,255,255,0.08); font:500 12px/1.4 system-ui, -apple-system, Segoe UI, sans-serif;">
                    <span style="display:inline-flex; align-items:center; gap:6px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>
                        <?php esc_html_e( 'Saved wireframe', 'gend-society' ); ?>
                    </span>
                    <div class="aipa-wireframe-toolbar__actions" style="display:flex; gap:8px; position:relative;">
                        <a href="<?php echo esc_url( $replace_url ); ?>"
                           style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); color:#fca5a5; padding:6px 12px; border-radius:6px; text-decoration:none; font-size:12px;"
                           onclick="return confirm('<?php echo esc_js( __( 'Discard the current wireframe and start over?', 'gend-society' ) ); ?>');">
                            <?php esc_html_e( 'Regenerate (Replace)', 'gend-society' ); ?>
                        </a>
                        <div class="aipa-wireframe-addon" style="position:relative;">
                            <button type="button"
                                    class="aipa-wireframe-addon__trigger"
                                    style="background:rgba(99,102,241,0.18); border:1px solid rgba(99,102,241,0.45); color:#a5b4fc; padding:6px 12px; border-radius:6px; cursor:pointer; font-size:12px;"
                                    onclick="var m=this.nextElementSibling; m.style.display=m.style.display==='block'?'none':'block';">
                                <?php esc_html_e( 'Regenerate (Add On) ▾', 'gend-society' ); ?>
                            </button>
                            <div class="aipa-wireframe-addon__menu" style="display:none; position:absolute; right:0; top:calc(100% + 6px); background:#0f172a; border:1px solid rgba(255,255,255,0.12); border-radius:8px; min-width:200px; padding:4px; z-index:10; box-shadow:0 12px 32px rgba(0,0,0,0.4);">
                                <a href="<?php echo esc_url( $section_url ); ?>" style="display:block; padding:8px 12px; color:#e2e8f0; text-decoration:none; border-radius:5px; font-size:12px;" onmouseover="this.style.background='rgba(99,102,241,0.18)'" onmouseout="this.style.background='transparent'">
                                    <?php esc_html_e( 'Add a section', 'gend-society' ); ?>
                                </a>
                                <a href="<?php echo esc_url( $newpage_url ); ?>" style="display:block; padding:8px 12px; color:#e2e8f0; text-decoration:none; border-radius:5px; font-size:12px;" onmouseover="this.style.background='rgba(99,102,241,0.18)'" onmouseout="this.style.background='transparent'">
                                    <?php esc_html_e( 'Add a new page', 'gend-society' ); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <iframe class="aipa-wireframe-saved__frame"
                    srcdoc="<?php echo $srcdoc; // already esc_attr'd above ?>"
                    sandbox="allow-scripts allow-same-origin allow-popups"
                    style="flex:1; width:100%; min-height:<?php echo esc_attr( $atts['height'] ); ?>; border:0; background:#fff; display:block;"></iframe>
        </div>
        <?php
        return (string) ob_get_clean();
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
