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

class Gend_Society_AI_Widget {

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
        $base = get_option( 'gend_society_gend_base_url' );
        if ( ! $base ) {
            $base = 'https://gend.me';
        }
        return untrailingslashit( apply_filters( 'gend_society_ai_hub_base', $base ) );
    }

    /** Pinned widget asset version on the hub (filterable for upgrades). */
    protected static function widget_js_file() {
        return apply_filters( 'gend_society_ai_widget_js_file', 'widget-app-v1.9.82.js' );
    }

    /** Only members who connected their gend.me account can use AI. */
    protected static function eligible() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        if ( is_admin() && ! current_user_can( 'edit_posts' ) ) {
            return false;
        }
        return self::on_hub_login() || ( class_exists( 'Gend_CP_OAuth_Client' )
            && method_exists( 'Gend_CP_OAuth_Client', 'is_connected' )
            && Gend_CP_OAuth_Client::is_connected( get_current_user_id() ) );
    }

    /**
     * An admin of a launched web app on the hub's own domain (gend.me/their-app). Their hub login already covers this
     * page, so the widget talks to the hub directly and needs no OAuth connection. A mapped domain is a different origin
     * and stays on the forwarder.
     */
    protected static function on_hub_login() {
        if ( ! is_multisite() || is_main_site() || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        if ( wp_parse_url( home_url(), PHP_URL_HOST ) !== wp_parse_url( network_home_url(), PHP_URL_HOST ) ) {
            return false;
        }
        return self::linked_group() > 0;
    }

    /** The group this site was launched for, when both sides agree. 0 otherwise. */
    protected static function linked_group() {
        if ( ! function_exists( 'groups_get_groupmeta' ) ) {
            return 0;
        }
        $group_id = (int) get_option( 'gdc_bp_group_id' );
        return $group_id && (int) groups_get_groupmeta( $group_id, 'gdc_site_id', true ) === get_current_blog_id() ? $group_id : 0;
    }

    /**
     * The group whose setup chat should meet this admin on this page, or 0: setup under way, or a new web app that
     * hasn't started it. Setting up stays in the chat, which also means nothing runs until they click.
     */
    protected static function setup_group() {
        static $found = null;
        if ( null !== $found ) {
            return $found;
        }
        $found = 0;
        if ( is_admin() || isset( $_GET['gend_embed'] ) || ! self::on_hub_login() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return $found;
        }
        $group_id = self::linked_group();
        $state    = groups_get_groupmeta( $group_id, '_aipa_onboarding', true );
        if ( is_array( $state ) && $state ) {
            $open = empty( $state['finished'] ) && empty( $state['dismissed'] );
        } else {
            $created = strtotime( (string) ( groups_get_group( $group_id )->date_created ?? '' ) );
            $open    = $created && $created >= time() - 30 * DAY_IN_SECONDS;
        }
        $found = $open ? $group_id : 0;
        return $found;
    }

    /** Scripts change with their file on this network; elsewhere a new Society release brings a fresh copy of the hub script. */
    protected static function asset_version( $file ) {
        $path = WP_PLUGIN_DIR . '/leo/assets/js/' . $file;
        return file_exists( $path ) ? GEND_SOCIETY_VERSION . '.' . filemtime( $path ) : GEND_SOCIETY_VERSION;
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
        // Hub-hosted canonical assets (cross-origin <script src> is fine).
        wp_enqueue_script( 'aipa-widget', $leo . self::widget_js_file(), array(), self::asset_version( self::widget_js_file() ), true );
        wp_enqueue_script( 'aipa-widget-leo-flows', $leo . 'widget-leo-flows.js', array( 'aipa-widget' ), self::asset_version( 'widget-leo-flows.js' ), true );
        wp_enqueue_script( 'leo-flow-engine', $leo . 'leo-flow-engine.js', array( 'aipa-widget', 'aipa-widget-leo-flows' ), self::asset_version( 'leo-flow-engine.js' ), true );
        wp_enqueue_script( 'aipa-widget-unified-chat', $leo . 'widget-unified-chat.js', array( 'aipa-widget', 'aipa-widget-leo-flows' ), self::asset_version( 'widget-unified-chat.js' ), true );

        // Config — REST base points at the LOCAL aipa/v1 forwarder, so the
        // widget never talks to the hub directly and never sees the bearer.
        $config = self::build_config();
        $json   = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        wp_add_inline_script( 'aipa-widget', 'window.AIPA_WIDGET = ' . $json . '; window.leoData = window.AIPA_WIDGET;', 'before' );
    }

    protected static function build_config() {
        $uid = get_current_user_id();
        // Resolve the OAuth client from the SAME place the exchange route does
        // (GS_AI_Proxy::aipa_client) so the authorize popup and the token
        // exchange always present the identical client_id + hub. Sourcing the
        // authorize client from gs_oauth_client_id() while the exchange used
        // the aipa_oauth_client_id site option produced an invalid_grant and
        // the "popup shows Authorizing… then closes, still logged out" bug.
        $oauth = ( class_exists( 'Gend_Society_AI_Proxy' ) && method_exists( 'Gend_Society_AI_Proxy', 'widget_oauth_client' ) )
            ? Gend_Society_AI_Proxy::widget_oauth_client()
            : array(
                'id'  => function_exists( 'gend_society_oauth_client_id' ) ? gend_society_oauth_client_id() : '',
                'hub' => self::hub_base(),
            );
        $oauth_client_id = (string) ( $oauth['id'] ?? '' );
        $oauth_hub_url   = untrailingslashit( (string) ( $oauth['hub'] ?? self::hub_base() ) );
        // On the hub's own domain the hub is the same origin: its routes take this login and nonce directly.
        $local = self::on_hub_login()
            ? esc_url_raw( network_home_url( 'wp-json/aipa/v1' ) )
            : esc_url_raw( get_rest_url( null, 'aipa/v1' ) );
        return apply_filters( 'aipa_widget_config', array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- foreign hook from leo.
            // Local namespace — forwarded to the hub by GS_AI_Proxy.
            'rest'              => $local,
            'rest_url'          => trailingslashit( esc_url_raw( get_rest_url( get_current_blog_id() ) ) ),
            'rest_assistant'    => $local,
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
            'oauthClientId'     => $oauth_client_id,
            'centralHubUrl'     => $oauth_hub_url,
            'oauthClientID'     => $oauth_client_id, // alt-case alias some widget builds read
            // Where the sign-in popup goes when this site uses gend.me's own OAuth server (Hydra); null = the legacy server.
            'oauthFlow'         => ! empty( $oauth['hydra'] ) && function_exists( 'gend_society_oauth_hydra_url' ) ? array(
                'authorize_url' => gend_society_oauth_hydra_url() . '/oauth2/auth',
                'redirect_uri'  => $oauth_hub_url . '/oauth2-bridge/callback',
                'scope'         => 'openid profile email offline_access',
                'extra'         => function_exists( 'gend_society_nr_tag_args' ) && function_exists( 'gend_society_oauth_is_hub_site' ) && ! gend_society_oauth_is_hub_site() ? gend_society_nr_tag_args() : new stdClass(),
            ) : null,
            'icon'              => function_exists( 'gend_society_remote_asset_url' ) ? gend_society_remote_asset_url( 'ai_widget_icon' ) : '',
            'leo_avatar'        => function_exists( 'gend_society_remote_asset_url' ) ? gend_society_remote_asset_url( 'ai_widget_leo_avatar' ) : '',
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
            'isSuperAdmin'      => is_super_admin(), // site-admin check, not a hub signal (104 audit)
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
        if ( self::setup_group() ) {
            echo '<script>window.GEND_UC_AUTO=' . (int) self::setup_group() . ';</script>';
        }
        // The Media Studio lives with Leo, on the hub, and this widget's REST already points there.
        $studio = 'leo/assets/studio/gend-media-studio';
        if ( file_exists( WP_PLUGIN_DIR . "/$studio.js" ) ) {
            $version = GEND_SOCIETY_VERSION . '.' . filemtime( WP_PLUGIN_DIR . "/$studio.js" );
            echo '<script>window.GEND_STUDIO=' . wp_json_encode( array( 'js' => plugins_url( "$studio.js" ) . '?v=' . $version, 'css' => plugins_url( "$studio.css" ) . '?v=' . $version ) ) . ';</script>';
        }
        // Image spots on a built page: whoever can edit it can fill them from the chat.
        if ( is_singular() && current_user_can( 'edit_post', get_the_ID() ) && self::linked_group() ) {
            echo '<script>window.GEND_UC_SPOTS=' . wp_json_encode( array( 'group' => (int) self::linked_group(), 'page' => get_the_title() ) ) . ';</script>';
        }
        echo "\n<!-- GS AI WIDGET END -->\n";
    }

    public static function shortcode_wireframe( $atts ) {
        // The widget removes its floating chat when it finds an embed. While setup is what this admin should see, the
        // old wireframe chat on a launched site's starter page steps aside.
        if ( self::setup_group() ) {
            return '';
        }
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
        $saved_html = class_exists( 'Gend_Society_Wireframe_Store' )
            ? Gend_Society_Wireframe_Store::get_html()
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
add_action( 'plugins_loaded', array( 'Gend_Society_AI_Widget', 'init' ), 20 );
