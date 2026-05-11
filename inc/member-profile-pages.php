<?php
/**
 * Member Profile Pages
 *
 * Registers a per-user `gdc_profile_page` CPT. One post is auto-created per
 * user (on registration and lazily for existing users). On the BuddyPress
 * member Activity/home tab the rendered post content is injected above the
 * activity stream. The profile owner sees an "Edit My Profile Page" button
 * that links to the WP-admin block editor for their post.
 *
 * Permission model: the `user_has_cap` filter grants each user the minimum
 * set of CPT capabilities to view and edit only their own profile page post.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── CPT Registration ─────────────────────────────────────────────────────────

add_action( 'init', 'gdc_register_profile_page_cpt' );
function gdc_register_profile_page_cpt() {
    register_post_type( 'gdc_profile_page', [
        'label'              => 'Profile Pages',
        'labels'             => [
            'name'               => 'Profile Pages',
            'singular_name'      => 'Profile Page',
            'edit_item'          => 'Edit Profile Page',
            'view_item'          => 'View Profile Page',
            'search_items'       => 'Search Profile Pages',
            'not_found'          => 'No profile pages found.',
            'not_found_in_trash' => 'No profile pages found in trash.',
        ],
        'description'        => 'One editable page per member, shown on their BuddyPress profile.',
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => true,
        'show_in_menu'       => false,   // hidden from admin menu sidebar
        'show_in_rest'       => true,    // REQUIRED for Gutenberg block editor
        'rest_base'          => 'gdc-profile-page',
        'supports'           => [ 'title', 'editor', 'revisions', 'custom-fields' ],
        'capability_type'    => 'gdc_profile_page',
        'map_meta_cap'       => true,
        'rewrite'            => false,
    ] );
}

// ─── Minimal block editor mode (strips WP admin bar + back button) ───────────
// Activated by ?gdc_embed=1 on post.php — used when the editor opens inside our
// frontend iframe overlay so the user sees only the Gutenberg editing surface.

// ── Performance: dequeue non-Gutenberg admin scripts/styles in embed mode ─────
// Many plugins (WooCommerce, etc.) enqueue heavy assets on every admin page.
// In embed mode we only need Gutenberg; strip everything else.
add_action( 'admin_enqueue_scripts', 'gdc_embed_strip_admin_scripts', 9999 );
function gdc_embed_strip_admin_scripts() {
    if ( empty( $_GET['gdc_embed'] ) ) return;
    $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
    if ( ! $post_id || get_post_type( $post_id ) !== 'gdc_profile_page' ) return;

    global $wp_scripts, $wp_styles;
    
    if ( isset( $wp_scripts->registered ) ) {
        foreach ( $wp_scripts->registered as $handle => $script ) {
            if ( strpos( $handle, 'elementor' ) !== false || strpos( $handle, 'woocommerce' ) !== false || strpos( $handle, 'youzify' ) !== false || strpos( $handle, 'wc-' ) === 0 ) {
                wp_dequeue_script( $handle );
            }
        }
    }
    
    if ( isset( $wp_styles->registered ) ) {
        foreach ( $wp_styles->registered as $handle => $style ) {
            if ( strpos( $handle, 'elementor' ) !== false || strpos( $handle, 'woocommerce' ) !== false || strpos( $handle, 'youzify' ) !== false || strpos( $handle, 'wc-' ) === 0 ) {
                wp_dequeue_style( $handle );
            }
        }
    }

    $remove_scripts = [
        'buddypress-admin', 'jquery-ui-sortable-min', 'wp-color-picker-alpha',
    ];

    foreach ( $remove_scripts as $h ) wp_dequeue_script( $h );
}

// ── Performance: slow down Heartbeat in embed mode — no need for frequent pings
add_filter( 'heartbeat_settings', 'gdc_embed_heartbeat_settings' );
function gdc_embed_heartbeat_settings( $settings ) {
    if ( ! empty( $_GET['gdc_embed'] ) ) {
        $settings['interval'] = 120; // default 60 s → 120 s
    }
    return $settings;
}

// Remove X-Frame-Options header so the block editor can load inside our iframe.
// Must run at priority 1 (before send_frame_options_header fires at priority 10).
add_action( 'admin_init', 'gdc_allow_embed_framing', 1 );
function gdc_allow_embed_framing() {
    if ( empty( $_GET['gdc_embed'] ) ) return;
    $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
    if ( ! $post_id || get_post_type( $post_id ) !== 'gdc_profile_page' ) return;
    remove_action( 'admin_init', 'send_frame_options_header' );
    @header_remove( 'X-Frame-Options' );
}

add_action( 'admin_head', 'gdc_minimal_block_editor_css' );
function gdc_minimal_block_editor_css() {
    if ( empty( $_GET['gdc_embed'] ) ) return;
    $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
    if ( ! $post_id || get_post_type( $post_id ) !== 'gdc_profile_page' ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    ?>
    <style id="gdc-embed-editor">
        /* Rely on WP's own is-fullscreen-mode CSS for sidebar/adminbar hiding.
           We just suppress the back button and noisy notices. */
        .edit-post-header__back,
        .editor-header__back,
        .edit-post-fullscreen-mode-close  { display: none !important; }
        .components-notice-list,
        #wpbody-content > .notice,
        #wpbody-content > .error          { display: none !important; }

        /* Force hide the WP admin bar and custom headers in the embed */
        #wpadminbar { display: none !important; height: 0 !important; overflow: hidden !important; }
        html.wp-toolbar { padding-top: 0 !important; margin-top: 0 !important; }
        body { margin-top: 0 !important; padding-top: 0 !important; }

        /* gend-society/assets/admin-script.js injects a custom 3D header
           (#main-3d-header) at the top of <body> on every wp-admin load with
           Digital Business / Build with LEO / Contract Wallet / Projects /
           Tasks / Sales / My Apps / Dashboard / Visit Site links. It's
           irrelevant inside the page-builder iframe — hide it. */
        #main-3d-header,
        .header-anchor-wrap { display: none !important; }

        /* admin-style.css reserves 56px at the top for the (now-hidden) header
           via --gs-header-h on #wpbody, the editor skeleton, the sidebar, the
           publish panel, etc. Collapse the variable to 0 in embed mode so all
           the var() references in admin-style.css naturally resolve to zero.
           Don't hard-code top/height on individual editor elements — admin-
           style.css is using CALCULATED values (top: var(--gs-header-h);
           height: calc(100vh - var(--gs-header-h))) so they correct
           themselves once the variable is 0. */
        :root, html, body { --gs-header-h: 0px !important; }
        #wpbody { padding-top: 0 !important; }
    <script>
    /* Apply is-fullscreen-mode as early as possible so WP's own CSS
       hides the sidebar and admin bar before the page paints. */
    document.addEventListener('DOMContentLoaded', function () {
        document.body.classList.add('is-fullscreen-mode');
    });
    </script>
    <?php
}

// Activate WP's native fullscreen mode via wp.data so the Gutenberg editor
// fills the iframe and hides the sidebar/admin-bar using WP's own CSS.
add_action( 'admin_footer', 'gdc_embed_fullscreen_js' );
function gdc_embed_fullscreen_js() {
    if ( empty( $_GET['gdc_embed'] ) ) return;
    $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
    if ( ! $post_id || get_post_type( $post_id ) !== 'gdc_profile_page' ) return;
    ?>
    <script id="gdc-embed-fullscreen">
    (function () {
        // Immediately stamp the body class so WP's own CSS kicks in at paint time
        document.body.classList.add('is-fullscreen-mode');

        // Also push the preference into Gutenberg's store so it doesn't revert.
        // wp.data may not be ready yet — poll until it is.
        function activate() {
            try {
                if ( window.wp && window.wp.data ) {
                    // WP 6.2+ uses core/preferences; older uses core/edit-post
                    var prefs = window.wp.data.dispatch('core/preferences');
                    if ( prefs && prefs.set ) {
                        prefs.set('core/edit-post', 'fullscreenMode', true);
                    }
                    var editPost = window.wp.data.dispatch('core/edit-post');
                    if ( editPost && editPost.toggleFeature ) {
                        var sel = window.wp.data.select('core/edit-post');
                        if ( sel && !sel.isFeatureActive('fullscreenMode') ) {
                            editPost.toggleFeature('fullscreenMode');
                        }
                    }
                    document.body.classList.add('is-fullscreen-mode');
                    return true;
                }
            } catch(e) {}
            return false;
        }

        if ( !activate() ) {
            var n = 0;
            var t = setInterval(function () {
                if ( activate() || ++n > 40 ) clearInterval(t);
            }, 150);
        }

        // Intercept "View Site" button — reload the parent profile page instead
        // of opening the site root in a new tab.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.gs-btn-site');
            if ( !btn ) return;
            e.preventDefault();
            if ( window.parent && window.parent !== window ) {
                window.parent.location.reload();
            }
        });
    }());
    </script>
    <?php
}

// ─── Capability filter ────────────────────────────────────────────────────────
// Grants each user the CPT-specific primitive caps for their own post.
// We detect a gdc_profile_page cap request by checking if any required
// primitive cap contains the string 'gdc_profile_page'.

add_filter( 'user_has_cap', 'gdc_profile_page_user_caps', 10, 4 );
function gdc_profile_page_user_caps( $allcaps, $caps, $args, $user ) {
    if ( ! ( $user instanceof WP_User ) || ! $user->ID ) {
        return $allcaps;
    }

    // Quick exit: only act when a cap relates to our CPT.
    // is_string() guards against null in $caps (PHP 8.x TypeError safety).
    $needs_profile_cap = false;
    foreach ( $caps as $cap ) {
        if ( is_string( $cap ) && strpos( $cap, 'gdc_profile_page' ) !== false ) {
            $needs_profile_cap = true;
            break;
        }
    }
    if ( ! $needs_profile_cap ) return $allcaps;

    $user_id = (int) $user->ID;
    $post_id = isset( $args[2] ) ? (int) $args[2] : 0;

    // Cache the user's own profile page ID to avoid repeated meta queries
    // (this filter fires many times per page load).
    static $id_cache = [];
    if ( ! array_key_exists( $user_id, $id_cache ) ) {
        $id_cache[ $user_id ] = (int) get_user_meta( $user_id, '_gdc_profile_page_id', true );
    }
    $own_page_id = $id_cache[ $user_id ];

    if ( ! $own_page_id ) return $allcaps;

    // Grant the cap if:
    //  • Checking a specific post AND it is this user's own profile page post
    //  • OR checking a general CPT-level cap (no specific post, e.g. REST API checks)
    if ( ! $post_id || $post_id === $own_page_id ) {
        foreach ( $caps as $cap ) {
            if ( is_string( $cap ) ) {
                $allcaps[ $cap ] = true;
            }
        }
    }

    return $allcaps;
}

// ─── Rendered-content cache invalidation ──────────────────────────────────────
// Bust the cached HTML when the user saves their profile page post.

add_action( 'save_post', 'gdc_bust_profile_page_render_cache', 10, 2 );
function gdc_bust_profile_page_render_cache( $post_id, $post ) {
    if ( ! $post instanceof WP_Post ) return;
    if ( $post->post_type !== 'gdc_profile_page' ) return;
    delete_transient( 'gdc_ppe_html_' . $post_id );
}

// ─── Auto-create a profile page for new users ─────────────────────────────────

add_action( 'user_register', 'gdc_create_user_profile_page', 20 );
function gdc_create_user_profile_page( $user_id ) {
    // Bail if the user already has a valid post
    $existing_id = get_user_meta( $user_id, '_gdc_profile_page_id', true );
    if ( $existing_id && get_post( (int) $existing_id ) ) return;

    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) return;

    $post_id = wp_insert_post( [
        'post_type'    => 'gdc_profile_page',
        'post_status'  => 'publish',
        'post_title'   => $user->display_name . "'s Profile",
        'post_author'  => $user_id,
        'post_content' => '',
        'meta_input'   => [ '_gdc_owner_user_id' => $user_id ],
    ] );

    if ( ! is_wp_error( $post_id ) ) {
        update_user_meta( $user_id, '_gdc_profile_page_id', $post_id );
    }
}

// ─── No batch migration — profile pages are created lazily on first profile view ─
// gdc_get_user_profile_page_id() creates the post on-demand for any user who
// doesn't have one yet.  Running wp_insert_post() in a batch on admin_init was
// triggering save_post hooks in third-party plugins and causing fatal errors.

// ─── Helper: get (or lazily create) a user's profile page post ID ─────────────

function gdc_get_user_profile_page_id( $user_id ) {
    $post_id = (int) get_user_meta( $user_id, '_gdc_profile_page_id', true );

    if ( $post_id && get_post( $post_id ) ) {
        return $post_id;
    }

    // Create on-demand if missing (e.g. user pre-dates migration)
    gdc_create_user_profile_page( $user_id );
    return (int) get_user_meta( $user_id, '_gdc_profile_page_id', true );
}

// ─── BuddyPress: inject profile page embed on the member Overview/home tab ───

add_action( 'bp_before_member_home_content', 'gdc_inject_profile_page_embed', 1 );
function gdc_inject_profile_page_embed() {
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) return;

    // Only on Youzify's Overview tab — the default member profile landing page
    if ( ! bp_is_current_component( 'overview' ) ) return;

    $viewed_user_id = (int) bp_displayed_user_id();
    if ( ! $viewed_user_id ) return;

    $post_id = gdc_get_user_profile_page_id( $viewed_user_id );
    if ( ! $post_id ) return;

    $current_user_id = (int) get_current_user_id();
    $is_own_profile  = ( $current_user_id > 0 && $current_user_id === $viewed_user_id );
    $can_edit        = $is_own_profile && current_user_can( 'edit_post', $post_id );

    $post        = get_post( $post_id );
    $raw_content = $post ? $post->post_content : '';
    $has_content = ! empty( trim( $raw_content ) );

    // Cache the rendered HTML to skip do_blocks() + the_content on repeat views.
    // Busted by gdc_bust_profile_page_render_cache() on save_post.
    if ( $has_content ) {
        $cache_key = 'gdc_ppe_html_' . $post_id;
        $rendered  = get_transient( $cache_key );
        if ( false === $rendered ) {
            $rendered = apply_filters( 'the_content', do_blocks( $raw_content ) );
            set_transient( $cache_key, $rendered, HOUR_IN_SECONDS );
        }
    } else {
        $rendered = '';
    }

    // gdc_embed=1 tells our admin_head hook to strip the WP admin bar + back button
    $edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit&gdc_embed=1' );
    ?>
    <section class="profile-init-bridge" id="gdc-profile-page-embed">
        <div class="profile-canvas-frame">

            <?php if ( $can_edit ) : ?>
            <button type="button" class="edit-profile-node gdc-ppe-edit-btn"
                    data-edit-url="<?php echo esc_url( $edit_url ); ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                Edit Profile Node
            </button>
            <?php endif; ?>

            <?php if ( $has_content ) : ?>

                <div class="gdc-ppe-content">
                    <?php echo $rendered; ?>
                </div>

            <?php elseif ( $is_own_profile ) : ?>

                <div class="init-icon-wrap">
                    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <line x1="3" y1="9" x2="21" y2="9"/>
                        <line x1="9" y1="21" x2="9" y2="9"/>
                    </svg>
                </div>
                <div class="init-text">
                    <h2>Your profile node is a blank canvas.</h2>
                </div>
                <button type="button" class="init-button gdc-ppe-edit-btn"
                        data-edit-url="<?php echo esc_url( $edit_url ); ?>">
                    Initialize Profile
                </button>

            <?php else : ?>

                <div class="init-icon-wrap">
                    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <line x1="3" y1="9" x2="21" y2="9"/>
                        <line x1="9" y1="21" x2="9" y2="9"/>
                    </svg>
                </div>
                <div class="init-text init-text--uninitialized">
                    <h2>Profile Node Uninitialized</h2>
                </div>

            <?php endif; ?>

        </div>
    </section>

    <?php if ( $can_edit ) : ?>
    <!-- Full-screen editor overlay — loaded lazily when the edit button is clicked -->
    <div class="gdc-ppe-modal" id="gdc-ppe-modal" aria-modal="true" role="dialog" aria-label="Profile Page Editor" hidden>

        <!-- Thin branded bar (always visible, sits above iframe) -->
        <div class="gdc-ppe-modal-bar">
            <span class="gdc-ppe-modal-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                Profile Page Editor
            </span>
            <button type="button" class="gdc-ppe-modal-done" id="gdc-ppe-done">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                Done
            </button>
        </div>

        <!-- Loading screen — shown while the iframe is fetching the block editor -->
        <div class="gdc-ppe-loading" id="gdc-ppe-loading">
            <div class="gdc-ppe-loading-ring">
                <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="30" cy="30" r="26" stroke="rgba(255,255,255,0.08)" stroke-width="4"/>
                    <circle class="gdc-ppe-arc" cx="30" cy="30" r="26" stroke="url(#gdc-grad)" stroke-width="4" stroke-linecap="round" stroke-dasharray="50 113"/>
                    <defs>
                        <linearGradient id="gdc-grad" x1="0" y1="0" x2="60" y2="60" gradientUnits="userSpaceOnUse">
                            <stop offset="0%" stop-color="#2e7dff"/>
                            <stop offset="100%" stop-color="#8b5cf6"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <p class="gdc-ppe-loading-title">We are setting up your Profile Page Builder</p>
            <p class="gdc-ppe-loading-sub">This only takes a moment&hellip;</p>
        </div>

        <!-- The actual editor — preloaded immediately in the background so it's
             ready (or nearly ready) the moment the user clicks Edit. -->
        <iframe class="gdc-ppe-frame" id="gdc-ppe-frame"
                src="<?php echo esc_url( $edit_url ); ?>"
                title="Profile Page Editor" allowfullscreen></iframe>
    </div>

    <script>
    (function () {
        var modal      = document.getElementById('gdc-ppe-modal');
        var frame      = document.getElementById('gdc-ppe-frame');
        var loading    = document.getElementById('gdc-ppe-loading');
        var doneBtn    = document.getElementById('gdc-ppe-done');
        var profileUrl = window.location.href;
        var editorReady = false;

        // ── Move modal to <body> so it escapes any CSS stacking context
        //    created by transforms/animations on ancestor elements.
        document.body.appendChild(modal);

        // Track when the preloaded iframe finishes loading.
        // If the modal is already open at that point, dismiss the loading screen.
        frame.addEventListener('load', function () {
            editorReady = true;
            if (!modal.hidden) {
                loading.classList.add('gdc-ppe-loading--done');
            }
        });

        function openEditor() {
            if (editorReady) {
                // Editor already loaded in background — show immediately.
                loading.classList.add('gdc-ppe-loading--done');
            } else {
                // Still loading — show the animated loading screen.
                loading.classList.remove('gdc-ppe-loading--done');
            }
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
        }

        function closeEditor() {
            modal.hidden = true;
            document.body.style.overflow = '';
            window.location.href = profileUrl;
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.gdc-ppe-edit-btn[data-edit-url]');
            if (btn) { e.preventDefault(); openEditor(); }
        });

        doneBtn.addEventListener('click', closeEditor);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) closeEditor();
        });
    }());
    </script>
    <?php endif; ?>
    <?php
}

// ─── Enqueue front-end styles ─────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', 'gdc_enqueue_profile_page_styles' );
function gdc_enqueue_profile_page_styles() {
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) return;

    // Register a virtual handle so we can attach inline CSS cleanly
    wp_register_style( 'gdc-profile-page', false, [], GS_VERSION );
    wp_enqueue_style( 'gdc-profile-page' );
    wp_add_inline_style( 'gdc-profile-page', gdc_profile_page_css() );
}

function gdc_profile_page_css() {
    return '
/* ── GDC Member Profile Page — Design Tokens ─────────────────────────── */
.profile-init-bridge {
    --terminal-blue:    #89C2E0;
    --terminal-magenta: #b608c9;
    --terminal-glass:   rgba(255,255,255,0.03);
    --terminal-border:  rgba(255,255,255,0.12);
    --obsidian:         #0b0e14;
}

/* ── Outer Section ────────────────────────────────────────────────────── */
.profile-init-bridge {
    background: var(--obsidian);
    padding: 60px 20px 100px;
    position: relative;
    z-index: 20;
    display: flex;
    justify-content: center;
}

/* ── Holographic Frame ────────────────────────────────────────────────── */
/* 1. Frame — 3D perspective flip entrance */
.profile-canvas-frame {
    max-width: 1200px;
    width: 100%;
    min-height: 400px;
    background: var(--terminal-glass);
    border: 1px solid var(--terminal-border);
    border-radius: 40px;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    box-shadow: 0 40px 100px rgba(0,0,0,0.6);
    overflow: hidden;
    opacity: 0;
    transform: perspective(2000px) rotateX(10deg) translateY(40px);
    animation: profileTerminalReady 1s cubic-bezier(0.16,1,0.3,1) forwards 0.5s;
}
@keyframes profileTerminalReady {
    to { opacity: 1; transform: perspective(2000px) rotateX(0deg) translateY(0); }
}

/* Ambient scan grid overlay */
.profile-canvas-frame::before {
    content: "";
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(137,194,224,0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(137,194,224,0.05) 1px, transparent 1px);
    background-size: 30px 30px;
    mask-image: radial-gradient(circle at center, black, transparent 80%);
    -webkit-mask-image: radial-gradient(circle at center, black, transparent 80%);
    pointer-events: none;
}

/* ── 2. Edit Node — slides in from the right ──────────────────────────── */
.edit-profile-node {
    position: absolute;
    top: 30px;
    right: 40px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: rgba(255,255,255,0.4);
    background: none;
    cursor: pointer;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    border: 1px solid rgba(255,255,255,0.1);
    padding: 8px 15px;
    border-radius: 10px;
    z-index: 10;
    transition: color 0.3s, border-color 0.3s, background 0.3s;
    opacity: 0;
    transform: translateX(20px);
    animation: nodeSlideIn 0.6s cubic-bezier(0.22,1,0.36,1) forwards 1.1s;
}
@keyframes nodeSlideIn {
    to { opacity: 1; transform: none; }
}
.edit-profile-node:hover {
    color: #fff;
    border-color: var(--terminal-magenta);
    background: rgba(182,8,201,0.1);
}
.edit-profile-node svg { flex-shrink: 0; }

/* ── 3. Icon — scales + fades in, then breathes ──────────────────────── */
.init-icon-wrap {
    color: var(--terminal-blue);
    margin-bottom: 20px;
    filter: drop-shadow(0 0 15px var(--terminal-blue));
    opacity: 0;
    transform: scale(0.7);
    animation:
        iconPhase   0.7s cubic-bezier(0.22,1,0.36,1) forwards 1.0s,
        pulseGhost  3s ease-in-out infinite 2.2s;
}
@keyframes iconPhase {
    to { opacity: 0.7; transform: scale(1); }
}
@keyframes pulseGhost {
    0%,100% { opacity: 0.4; transform: scale(1); }
    50%     { opacity: 1;   transform: scale(1.1); }
}

/* ── 4. Heading — slides up from below ───────────────────────────────── */
.init-text {
    opacity: 0;
    transform: translateY(16px);
    animation: textReveal 0.7s cubic-bezier(0.22,1,0.36,1) forwards 1.15s;
}
@keyframes textReveal {
    to { opacity: 1; transform: none; }
}
.init-text h2 {
    font-family: "Inter", sans-serif;
    color: #fff;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 2px;
    font-size: 1.2rem;
    margin-bottom: 30px;
    text-align: center;
}
.init-text--uninitialized h2 {
    color: rgba(255,255,255,0.35);
}

/* ── 5. CTA Button — scales up from below ────────────────────────────── */
.init-button {
    background: transparent;
    border: 1px solid var(--terminal-blue);
    color: var(--terminal-blue) !important;
    padding: 18px 45px;
    border-radius: 100px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 3px;
    font-size: 0.85rem;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    transition: color 0.4s cubic-bezier(0.175,0.885,0.32,1.275),
                transform 0.4s cubic-bezier(0.175,0.885,0.32,1.275),
                box-shadow 0.4s;
    opacity: 0;
    transform: scale(0.9) translateY(10px);
    animation: buttonActivate 0.7s cubic-bezier(0.22,1,0.36,1) forwards 1.3s;
}
@keyframes buttonActivate {
    to { opacity: 1; transform: none; }
}
.init-button::after {
    content: "";
    position: absolute;
    inset: 0;
    background: var(--terminal-blue);
    opacity: 0;
    transition: opacity 0.3s;
    z-index: -1;
}
.init-button:hover {
    color: var(--obsidian) !important;
    transform: scale(1.05) translateY(-5px);
    box-shadow: 0 0 30px var(--terminal-blue);
}
.init-button:hover::after { opacity: 1; }

/* ── 6. Rendered Content — fades + slides up (has-content state) ─────── */
.gdc-ppe-content {
    padding: 40px 48px;
    width: 100%;
    box-sizing: border-box;
    color: rgba(255,255,255,0.86);
    line-height: 1.72;
    overflow-wrap: break-word;
    opacity: 0;
    transform: translateY(20px);
    animation: contentReveal 0.8s cubic-bezier(0.22,1,0.36,1) forwards 0.9s;
}
@keyframes contentReveal {
    to { opacity: 1; transform: none; }
}

/* Block content typography */
.gdc-ppe-content h1,
.gdc-ppe-content h2,
.gdc-ppe-content h3,
.gdc-ppe-content h4 { color: rgba(255,255,255,0.92); margin-top: 0; }
.gdc-ppe-content p   { color: rgba(255,255,255,0.78); margin-bottom: 1em; }
.gdc-ppe-content a   { color: #89C2E0; text-decoration: underline; text-underline-offset: 3px; }
.gdc-ppe-content a:hover { color: #b0d4ff; }
.gdc-ppe-content img,
.gdc-ppe-content .wp-block-image img { max-width: 100%; border-radius: 10px; }
.gdc-ppe-content ul,
.gdc-ppe-content ol  { color: rgba(255,255,255,0.78); padding-left: 1.4em; }
.gdc-ppe-content blockquote {
    border-left: 3px solid rgba(137,194,224,0.5);
    margin-left: 0; padding-left: 16px;
    color: rgba(255,255,255,0.55); font-style: italic;
}
.gdc-ppe-content hr { border: none; border-top: 1px solid rgba(255,255,255,0.08); margin: 20px 0; }
.gdc-ppe-content pre,
.gdc-ppe-content code { background: rgba(0,0,0,0.35); border-radius: 6px; font-size: 0.88em; color: #89C2E0; }
.gdc-ppe-content pre  { padding: 14px 18px; overflow-x: auto; }
.gdc-ppe-content code { padding: 2px 6px; }

/* ── Full-screen editor overlay ───────────────────────────────────────── */
.gdc-ppe-modal {
    position: fixed;
    inset: 0;
    z-index: 2147483647; /* max 32-bit int — beats all themes/plugins */
    display: flex;
    flex-direction: column;
    background: #0d0d1a;
    overflow: hidden;
}
.gdc-ppe-modal[hidden] { display: none; }

/* Thin branded "done" bar — fixed height at top, iframe + loading fill the rest */
.gdc-ppe-modal-bar {
    position: relative;
    z-index: 20;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 44px;
    padding: 0 16px;
    background: rgba(10,10,24,0.98);
    border-bottom: 1px solid rgba(255,255,255,0.07);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
}
.gdc-ppe-modal-title {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: rgba(255,255,255,0.55);
    font-size: 12.5px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.gdc-ppe-modal-done {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 18px;
    background: rgba(46,125,255,0.15);
    border: 1px solid rgba(46,125,255,0.40);
    border-radius: 30px;
    color: #7ab8ff;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.2s, color 0.2s, transform 0.15s;
}
.gdc-ppe-modal-done:hover {
    background: rgba(46,125,255,0.28);
    color: #b0d4ff;
    transform: translateY(-1px);
}

/* Loading screen — overlays the iframe slot while the editor boots */
.gdc-ppe-loading {
    position: absolute;
    inset: 44px 0 0 0; /* below the bar */
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 20px;
    background: #0d0d1a;
    z-index: 10;
    transition: opacity 0.4s ease;
    pointer-events: none;
}
.gdc-ppe-loading--done {
    opacity: 0;
}
.gdc-ppe-loading-ring {
    width: 64px;
    height: 64px;
    animation: gdcSpin 1.4s linear infinite;
}
@keyframes gdcSpin {
    to { transform: rotate(360deg); }
}
.gdc-ppe-arc {
    transform-origin: 30px 30px;
    animation: gdcArcPulse 1.4s ease-in-out infinite;
}
@keyframes gdcArcPulse {
    0%   { stroke-dasharray: 15 148; stroke-dashoffset: 0; }
    50%  { stroke-dasharray: 100 63; stroke-dashoffset: -40; }
    100% { stroke-dasharray: 15 148; stroke-dashoffset: -163; }
}
.gdc-ppe-loading-title {
    margin: 0 !important;
    color: #ffffff !important;
    font-family: -apple-system, BlinkMacSystemFont, sans-serif !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    letter-spacing: 0.01em !important;
    text-shadow: 0 0 24px rgba(46,125,255,0.6) !important;
}
.gdc-ppe-loading-sub {
    margin: 0 !important;
    color: rgba(255,255,255,0.55) !important;
    font-family: -apple-system, BlinkMacSystemFont, sans-serif !important;
    font-size: 13px !important;
    font-weight: 400 !important;
}

/* The editor iframe fills all remaining space behind the loading overlay */
.gdc-ppe-frame {
    position: absolute;
    inset: 44px 0 0 0; /* below the bar */
    width: 100%;
    height: calc(100% - 44px);
    border: none;
    display: block;
    background: #fff;
}
';
}

// ─── BuddyPress Wallet Profile Tab ──────────────────────────────────────────

add_action( 'bp_setup_nav', 'gs_add_wallet_profile_tab', 100 );
function gs_add_wallet_profile_tab() {
    if ( ! function_exists( 'bp_core_new_nav_item' ) ) {
        return;
    }
    
    bp_core_new_nav_item( [
        'name'                    => __( 'Wallet', 'gend-society' ),
        'slug'                    => 'member-wallet',
        'screen_function'         => 'gs_wallet_profile_screen',
        'position'                => 35,
        'item_css_id'             => 'wallet',
        'show_for_displayed_user' => true,
    ] );

    // Remove any other tab named "Wallet" to prevent duplicates
    // This handles cases where myCred or Youzify might have registered a similar tab.
    $primary_nav = buddypress()->members->nav->get_primary();
    foreach ( $primary_nav as $nav_item ) {
        if ( $nav_item['slug'] !== 'member-wallet' && ( strpos( strtolower( $nav_item['name'] ), 'wallet' ) !== false ) ) {
            bp_core_remove_nav_item( $nav_item['slug'] );
        }
    }
}

function gs_wallet_profile_screen() {
    add_action( 'bp_template_title', '__return_empty_string' );
    add_action( 'bp_template_content', 'gs_wallet_profile_screen_content' );
    bp_core_load_template( 'members/single/plugins' );
}

function gs_wallet_profile_screen_content() {
    // Only show if it is the current user's profile
    if ( ! bp_is_my_profile() ) {
        echo '<p>' . esc_html__( 'This wallet is private.', 'gend-society' ) . '</p>';
        return;
    }

    // Force full width and hide sidebar for the wallet tab.
    // Scoped to .member-wallet body class which BP adds automatically for this component slug.
    echo '<style>
        /* ── Wallet tab: hide sidebar, make main column full width ───────── */
        /* The actual two-column constraint is on .youzify-right-sidebar-layout
           (display:grid; grid-template-columns: calc(72% - 35px) 28%). Without
           collapsing that grid first, the .youzify-main-column width:100% below
           is just 100% of its 72% grid cell, not the row. */
        .member-wallet .youzify-right-sidebar-layout,
        .member-wallet .youzify-left-sidebar-layout {
            display: block !important;
            grid-template-columns: 1fr !important;
            grid-gap: 0 !important;
        }
        .member-wallet .youzify-sidebar-column,
        .member-wallet .youzify-sidebar,
        .member-wallet .yz-sidebar-column,
        .member-wallet .youzify-profile-sidebar,
        .member-wallet #secondary {
            display: none !important;
        }
        .member-wallet .youzify-page-main-content {
            max-width: none !important;
            width: 100% !important;
        }

        .member-wallet .youzify-main-column,
        .member-wallet .youzify-content,
        .member-wallet .yz-main-column,
        .member-wallet #primary {
            width: 100% !important;
            flex: 0 0 100% !important;
            max-width: 100% !important;
            border: none !important;
            background: transparent !important;
            padding: 0 !important;
            margin: 0 !important;
            box-shadow: none !important;
        }

        /* Strip every Youzify wrapper so the wallet shortcode renders edge-to-edge */
        .member-wallet .youzify-main-column-inner,
        .member-wallet .youzify-widget,
        .member-wallet .yz-widget,
        .member-wallet .youzify-widget-head,
        .member-wallet .youzify-widget-content,
        .member-wallet .youzify-inner-content,
        .member-wallet .youzify-page-main-content,
        .member-wallet .youzify-profile-main-content {
            width: 100% !important;
            max-width: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: transparent !important;
            overflow: visible !important;
        }

        /* Hide the Youzify/BP injected page title above the content */
        .member-wallet .youzify-page-title,
        .member-wallet .bp-page-title,
        .member-wallet .entry-title { display: none !important; }

        /* gend_wallet shortcode outer wrapper */
        .gend-wallet {
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            box-sizing: border-box !important;
        }
    </style>';
    
    // Direct CSS injection as a fallback to ensure styling is applied even if enqueue fails
    $css_url = plugins_url( 'reward-programs/assets/frontend-wallet.css' );
    echo '<link rel="stylesheet" id="gend-wallet-frontend-profile-css" href="' . esc_url( $css_url ) . '?ver=2.0.1" type="text/css" media="all" />';
    
    // The store my-account wallet uses the gend_wallet shortcode.
    // Sometimes plugins inject nested shortcodes (like elementor-template) into its filters.
    // We will render it and parse any nested shortcodes (fixing typographical quotes if present).
    $wallet_content = do_shortcode( '[gend_wallet]' );
    
    // If it's still blank, try to see if mycred is initialized
    if ( empty( trim( $wallet_content ) ) ) {
        if ( ! function_exists( 'mycred_get_types' ) ) {
            $wallet_content = '<p>Error: myCred is not active.</p>';
        } else {
            $wallet_content = '<p>Error: Wallet content is empty. Check if point types are configured.</p>';
        }
    }

    $wallet_content = str_replace( ['”', '“', '″'], '"', $wallet_content );
    
    echo do_shortcode( $wallet_content );
}

// ─── Member Profile Groups Page — Tabbed Interface ───────────────────────────
//
// Wraps the standard BP groups list in a two-tab layout:
//   Tab 1 "Groups"   → existing groups-loop content (unchanged)
//   Tab 2 "Projects" → psoo_project_manager_groups shortcode (PM dashboard)
//
// Hooks: bp_before_member_groups_content / bp_after_member_groups_content
// (defined in bp-legacy members/single/groups.php, case 'my-groups').

add_action( 'bp_before_member_groups_content', 'gs_member_groups_tabs_open', 1 );
add_action( 'bp_after_member_groups_content',  'gs_member_groups_tabs_close', 99 );

function gs_member_groups_tabs_open() {
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
        return;
    }

    // Memberships tab is only meaningful on the viewer's OWN profile —
    // gdc_render_account_memberships_endpoint() reads wu_get_current_customer()
    // (the logged-in user), not the displayed user, so showing it on others'
    // profiles would leak the viewer's own memberships across pages.
    $gs_show_memberships = function_exists( 'bp_is_my_profile' ) && bp_is_my_profile()
        && function_exists( 'gdc_render_account_memberships_endpoint' );
    ?>
    <style id="gs-member-groups-tabs-css">
    /* ── Member profile groups tab interface ─────────────────────────────── */
    .gs-member-groups-wrap { width: 100%; }

    .gs-member-groups-tabs {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        padding-bottom: 0;
    }
    .gs-member-groups-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 22px;
        background: transparent;
        border: none;
        border-bottom: 2px solid transparent;
        color: #64748b;
        font-family: "Inter", sans-serif;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        cursor: pointer;
        transition: color 0.2s, border-color 0.2s;
        margin-bottom: -1px;
    }
    .gs-member-groups-tab:hover {
        color: rgba(255,255,255,0.75);
    }
    .gs-member-groups-tab.is-active {
        color: #b608c9;
        border-bottom-color: #b608c9;
    }

    .gs-member-groups-panel { display: none; }
    .gs-member-groups-panel.is-active { display: block; }

    /* ── Groups page: full-width main column, hide right sidebar ──────────────
       Confirmed DOM ancestor chain from browser console:
         #youzify > #youzify-bp.youzify > .youzify-content
           > <main>.youzify-page-main-content
             > .youzify-right-sidebar-layout         (display:grid 72%/28%)
               > .youzify-main-column.grid-column    (our wrap parent)

       All overrides are scoped to body.my-groups so other profile tabs
       (Portfolio, Connections, Activity, etc.) keep their normal layout.
       The .youzify-sidebar bare selector is intentionally NOT used — it's
       too broad and was blanking unrelated widgets. */
    body.my-groups .youzify-right-sidebar-layout,
    body.my-groups .youzify-left-sidebar-layout {
        display: block !important;
        grid-template-columns: 1fr !important;
        grid-gap: 0 !important;
    }
    body.my-groups .youzify-main-column,
    body.my-groups .youzify-main-column.grid-column {
        width: 100% !important;
        max-width: none !important;
        flex: 0 0 100% !important;
    }
    body.my-groups .gs-member-groups-wrap,
    body.my-groups .youzify-page-main-content,
    body.my-groups .youzify-content {
        max-width: none !important;
        width: 100% !important;
    }
    body.my-groups .youzify-profile-sidebar,
    body.my-groups .youzify-sidebar-column,
    body.my-groups .yz-sidebar-column {
        display: none !important;
    }

    /* ── Memberships panel: undo Youzify's .youzify table { background:#fff }
       and tbody{ text-align:center; color:#7c838a } overrides so the dark-
       glass .gdc-membership-card styling can show through. ── */
    .gs-member-groups-panel[data-gs-panel="memberships"] table,
    .gs-member-groups-panel[data-gs-panel="memberships"] table thead,
    .gs-member-groups-panel[data-gs-panel="memberships"] table tbody,
    .gs-member-groups-panel[data-gs-panel="memberships"] table tfoot,
    .gs-member-groups-panel[data-gs-panel="memberships"] table tbody tr,
    .gs-member-groups-panel[data-gs-panel="memberships"] table tbody td,
    .gs-member-groups-panel[data-gs-panel="memberships"] table thead tr,
    .gs-member-groups-panel[data-gs-panel="memberships"] table thead tr th,
    .gs-member-groups-panel[data-gs-panel="memberships"] table tfoot tr,
    .gs-member-groups-panel[data-gs-panel="memberships"] table tfoot tr th {
        background-color: transparent !important;
        background: transparent !important;
        border: none !important;
        text-align: inherit !important;
        color: inherit !important;
    }
    .gs-member-groups-panel[data-gs-panel="memberships"] table tbody td {
        padding: 0 !important;
    }

    /* ── Groups panel: dark-theme styling for the BP groups loop. The
       member-profile-header.php styles target body.youzify-profile, which
       this install doesn't add — so we restyle here, scoped to our panel. ── */
    .gs-member-groups-panel[data-gs-panel="groups"] #groups-list,
    .gs-member-groups-panel[data-gs-panel="groups"] ul.item-list {
        list-style: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .gs-member-groups-panel[data-gs-panel="groups"] ul.item-list > li {
        list-style: none !important;
        background: rgba(255,255,255,0.03) !important;
        border: 1px solid rgba(255,255,255,0.08) !important;
        border-radius: 12px !important;
        padding: 16px 20px !important;
        margin: 0 0 12px 0 !important;
        display: flex !important;
        align-items: center !important;
        gap: 16px !important;
        color: #e2e8f0 !important;
    }
    .gs-member-groups-panel[data-gs-panel="groups"] ul.item-list > li::marker {
        content: '' !important;
    }
    .gs-member-groups-panel[data-gs-panel="groups"] ul.item-list > li:hover {
        background: rgba(255,255,255,0.05) !important;
        border-color: rgba(137,194,224,0.3) !important;
    }
    .gs-member-groups-panel[data-gs-panel="groups"] ul.item-list .item-avatar img {
        width: 56px !important;
        height: 56px !important;
        border-radius: 12px !important;
        object-fit: cover !important;
    }
    .gs-member-groups-panel[data-gs-panel="groups"] ul.item-list .item {
        flex: 1 1 auto !important;
        min-width: 0 !important;
    }
    .gs-member-groups-panel[data-gs-panel="groups"] ul.item-list .item-title,
    .gs-member-groups-panel[data-gs-panel="groups"] ul.item-list .item-title a {
        color: #f8fafc !important;
        font-weight: 600 !important;
        font-size: 1rem !important;
        text-decoration: none !important;
    }
    .gs-member-groups-panel[data-gs-panel="groups"] ul.item-list .item-meta,
    .gs-member-groups-panel[data-gs-panel="groups"] ul.item-list .item-desc,
    .gs-member-groups-panel[data-gs-panel="groups"] ul.item-list .item .meta {
        color: #94a3b8 !important;
        font-size: 0.85rem !important;
    }
    .gs-member-groups-panel[data-gs-panel="groups"] .pagination {
        color: #cbd5e1 !important;
        padding: 12px 0 !important;
    }
    </style>

    <div class="gs-member-groups-wrap">

        <div class="gs-member-groups-tabs" role="tablist">
            <?php if ( $gs_show_memberships ) : ?>
            <button type="button"
                    class="gs-member-groups-tab is-active"
                    data-gs-panel="memberships"
                    role="tab"
                    aria-selected="true">
                <?php esc_html_e( 'Memberships', 'gend-society' ); ?>
            </button>
            <?php endif; ?>
            <button type="button"
                    class="gs-member-groups-tab<?php echo $gs_show_memberships ? '' : ' is-active'; ?>"
                    data-gs-panel="groups"
                    role="tab"
                    aria-selected="<?php echo $gs_show_memberships ? 'false' : 'true'; ?>">
                <?php esc_html_e( 'Groups', 'gend-society' ); ?>
            </button>
            <button type="button"
                    class="gs-member-groups-tab"
                    data-gs-panel="projects"
                    role="tab"
                    aria-selected="false">
                <?php esc_html_e( 'My Projects', 'gend-society' ); ?>
            </button>
        </div>

        <?php if ( $gs_show_memberships ) : ?>
        <div class="gs-member-groups-panel is-active" data-gs-panel="memberships" role="tabpanel">
            <?php
            // The shared customer-surface CSS (membership cards, subgroup tags,
            // domain-stage tags) only auto-enqueues on is_account_page(). On
            // the BP profile we have to inject it manually, otherwise the
            // membership card renders unstyled (faded text, broken layout).
            $gs_shared_css = plugins_url( 'vendor-app-manager/assets/css/gdc-customer-shared.css' );
            echo '<link rel="stylesheet" id="gdc-customer-shared-bp-profile" href="' . esc_url( $gs_shared_css ) . '?ver=1.0.0" type="text/css" media="all" />';
            gdc_render_account_memberships_endpoint();
            ?>
        </div>
        <?php endif; ?>

        <div class="gs-member-groups-panel<?php echo $gs_show_memberships ? '' : ' is-active'; ?>" data-gs-panel="groups" role="tabpanel">
    <?php
}

function gs_member_groups_tabs_close() {
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
        return;
    }

    // Resolve a group_id so [psoo_pm_group_projects] renders correctly.
    // Use the displayed user's most recently active group.
    $displayed_user_id = (int) bp_displayed_user_id();
    $group_id          = 0;

    if ( $displayed_user_id > 0 && function_exists( 'groups_get_groups' ) ) {
        $result = groups_get_groups( [
            'user_id'             => $displayed_user_id,
            'per_page'            => 1,
            'orderby'             => 'last_activity',
            'order'               => 'DESC',
            'show_hidden'         => true,
            'exclude_admins_mods' => false,
        ] );
        if ( ! empty( $result['groups'] ) ) {
            $group_id = (int) $result['groups'][0]->id;
        }
    }
    ?>
        </div><!-- /panel:groups -->

        <div class="gs-member-groups-panel" data-gs-panel="projects" role="tabpanel">
            <?php
            if ( $group_id && shortcode_exists( 'psoo_pm_group_projects' ) ) {
                // Render the full native PM dashboard (All Projects / My Tasks / Calendar / Reports / Settings)
                // for the member's most recently active group.
                echo do_shortcode( '[psoo_pm_group_projects group_id="' . $group_id . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } elseif ( shortcode_exists( 'psoo_pm_group_projects' ) ) {
                echo '<p class="psoo-pm-empty">' . esc_html__( 'Join a group to view your Projects dashboard here.', 'gend-society' ) . '</p>';
            } else {
                echo '<p class="psoo-pm-empty">' . esc_html__( 'Projects dashboard not available.', 'gend-society' ) . '</p>';
            }
            ?>
        </div><!-- /panel:projects -->

    </div><!-- /.gs-member-groups-wrap -->

    <script>
    (function () {
        var wrap = document.currentScript ? document.currentScript.closest('.gs-member-groups-wrap') : null;
        if ( ! wrap ) {
            wrap = document.querySelector('.gs-member-groups-wrap');
        }
        if ( ! wrap ) return;

        var tabs   = wrap.querySelectorAll('.gs-member-groups-tab');
        var panels = wrap.querySelectorAll('.gs-member-groups-panel');

        tabs.forEach( function ( tab ) {
            tab.addEventListener( 'click', function () {
                var target = tab.getAttribute('data-gs-panel');

                tabs.forEach( function ( t ) {
                    t.classList.remove('is-active');
                    t.setAttribute('aria-selected', 'false');
                } );
                panels.forEach( function ( p ) {
                    p.classList.remove('is-active');
                } );

                tab.classList.add('is-active');
                tab.setAttribute('aria-selected', 'true');

                var panel = wrap.querySelector('[data-gs-panel="' + target + '"].gs-member-groups-panel');
                if ( panel ) panel.classList.add('is-active');
            } );
        } );

        // ── Brute-force full-width — walk up from our wrap to <body> and
        // force every ancestor to width:100% / max-width:none via inline
        // !important. Bypasses guessing which Youzify/theme/plugin class is
        // creating the constraint: every link in the actual ancestor chain
        // gets expanded.
        function gsForceWidthOnAncestors () {
            var node  = wrap.parentElement;
            var depth = 0;
            var report = [];
            while ( node && node !== document.body && depth < 30 ) {
                node.style.setProperty( 'max-width',     'none',     'important' );
                node.style.setProperty( 'width',         '100%',     'important' );
                node.style.setProperty( 'margin-left',   '0',        'important' );
                node.style.setProperty( 'margin-right',  '0',        'important' );
                node.style.setProperty( 'padding-left',  '0',        'important' );
                node.style.setProperty( 'padding-right', '0',        'important' );
                node.style.setProperty( 'flex',          '0 0 100%', 'important' );
                report.push(
                    '<' + node.tagName.toLowerCase() + '>'
                    + ( node.id ? '#' + node.id : '' )
                    + ( node.className ? '.' + String( node.className ).trim().split( /\s+/ ).join( '.' ) : '' )
                );
                node = node.parentElement;
                depth++;
            }
            // Sidebar siblings → just remove them. The bare .youzify-sidebar
            // class is intentionally NOT in this list — it matches widgets
            // inside the BP groups loop and was wiping out group cards.
            document.querySelectorAll(
                '.youzify-profile-sidebar, .youzify-sidebar-column, .yz-sidebar-column, #secondary'
            ).forEach( function ( s ) { s.remove(); } );
            // Our own wrap full-width too.
            wrap.style.setProperty( 'max-width', 'none', 'important' );
            wrap.style.setProperty( 'width',     '100%', 'important' );
            // One-time debug breadcrumb in the browser console.
            if ( ! window.__gsForceWidthLogged ) {
                window.__gsForceWidthLogged = true;
                console.log( '[gs-groups] body class:', document.body.className );
                console.log( '[gs-groups] forced full-width on ancestors:', report );
            }
        }

        gsForceWidthOnAncestors();
        setTimeout( gsForceWidthOnAncestors,    0 );
        setTimeout( gsForceWidthOnAncestors,  250 );
        setTimeout( gsForceWidthOnAncestors, 1000 );
        window.addEventListener( 'load',   gsForceWidthOnAncestors );
        window.addEventListener( 'resize', gsForceWidthOnAncestors );

        // Observe inline-style writes on every ancestor — revert any change.
        if ( window.MutationObserver ) {
            var anc = wrap.parentElement;
            var d   = 0;
            while ( anc && anc !== document.body && d < 30 ) {
                ( function ( el ) {
                    var obs = new MutationObserver( function () {
                        if ( el.style.maxWidth !== 'none' || el.style.width !== '100%' ) {
                            el.style.setProperty( 'max-width', 'none', 'important' );
                            el.style.setProperty( 'width',     '100%', 'important' );
                        }
                    } );
                    obs.observe( el, { attributes: true, attributeFilter: [ 'style' ] } );
                } )( anc );
                anc = anc.parentElement;
                d++;
            }
        }
    }());
    </script>
    <?php
}

// ─── Member Profile Friends Page — Tabbed Interface ──────────────────────────
//
// Wraps the standard BP friends list in a two-tab layout:
//   Tab 1 "My Connections" → existing friends-loop content (unchanged)
//   Tab 2 "Sales Team"     → earnings dashboard (aas_earnings_endpoint_content)
//
// Hooks: bp_before_member_body / bp_after_member_body (fired by Youzify get_tabs()
// directly, so guaranteed to fire regardless of which BP template stack is active).
// Guards: bp_is_user_friends() ensures we only wrap the friends/connections page.

add_action( 'bp_before_member_body', 'gs_member_friends_tabs_open', 1 );
add_action( 'bp_after_member_body',  'gs_member_friends_tabs_close', 99 );

function gs_member_friends_tabs_open() {
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
        return;
    }
    if ( ! function_exists( 'bp_is_user_friends' ) || ! bp_is_user_friends() ) {
        return;
    }
    // Only wrap the main friends list, not sub-pages like /requests/
    if ( function_exists( 'bp_is_current_action' ) && bp_is_current_action( 'requests' ) ) {
        return;
    }
    ?>
    <style id="gs-member-friends-tabs-css">
    /* ── Member profile connections tab interface ─────────────────────────── */
    .gs-member-friends-wrap { width: 100%; }

    .gs-member-friends-tabs {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        padding-bottom: 0;
    }
    .gs-member-friends-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 22px;
        background: transparent;
        border: none;
        border-bottom: 2px solid transparent;
        color: #64748b;
        font-family: "Inter", sans-serif;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        cursor: pointer;
        transition: color 0.2s, border-color 0.2s;
        margin-bottom: -1px;
    }
    .gs-member-friends-tab:hover {
        color: rgba(255,255,255,0.75);
    }
    .gs-member-friends-tab.is-active {
        color: #b608c9;
        border-bottom-color: #b608c9;
    }

    .gs-member-friends-panel { display: none; }
    .gs-member-friends-panel.is-active { display: block; }

    /* ── Connections page: full-width main column, hide right sidebar.
       Scoped to body.my-friends so other profile tabs are unaffected. */
    body.my-friends .youzify-right-sidebar-layout,
    body.my-friends .youzify-left-sidebar-layout {
        display: block !important;
        grid-template-columns: 1fr !important;
        grid-gap: 0 !important;
    }
    body.my-friends .youzify-main-column,
    body.my-friends .youzify-main-column.grid-column {
        width: 100% !important;
        max-width: none !important;
        flex: 0 0 100% !important;
    }
    body.my-friends .gs-member-friends-wrap,
    body.my-friends .youzify-page-main-content,
    body.my-friends .youzify-content {
        max-width: none !important;
        width: 100% !important;
    }
    body.my-friends .youzify-profile-sidebar,
    body.my-friends .youzify-sidebar-column,
    body.my-friends .yz-sidebar-column {
        display: none !important;
    }

    /* ── Youzify table reset — youzify.css forces white bg, no borders, centered
       text on all .youzify table/td/th elements; restore AAS table styles here.
       Scope is the whole connections wrap so the AAS dashboard tables stay
       styled no matter which top-level panel they end up in (referral-sales /
       sales-team / payments — note JS relocates the latter two after render). */
    .gs-member-friends-wrap table {
        background-color: transparent !important;
        border: none !important;
        box-shadow: none !important;
        margin-bottom: 0 !important;
        border-collapse: collapse !important;
        border-spacing: 0 !important;
    }
    .gs-member-friends-wrap table thead tr,
    .gs-member-friends-wrap table tfoot tr {
        background-color: transparent !important;
        color: inherit !important;
        border-bottom: none !important;
    }
    .gs-member-friends-wrap table thead tr th,
    .gs-member-friends-wrap table tfoot tr th {
        border: none !important;
        color: rgba(226, 232, 240, 0.55) !important;
        font-size: 0.75rem !important;
        font-weight: 600 !important;
        line-height: 1.4 !important;
        padding: 0 22px 12px !important;
        text-align: left !important;
        letter-spacing: 0.24em !important;
        text-transform: uppercase !important;
        vertical-align: middle !important;
        background-color: transparent !important;
    }
    .gs-member-friends-wrap table tbody tr {
        text-align: left !important;
        border-bottom: none !important;
        background-color: transparent !important;
    }
    .gs-member-friends-wrap table tbody td {
        padding: clamp(14px, 2vw, 22px) !important;
        color: #e2e8f0 !important;
        border: none !important;
        border-top: 1px solid rgba(99, 102, 241, 0.35) !important;
        border-bottom: 1px solid rgba(22, 24, 38, 0.6) !important;
        background: rgba(11, 17, 34, 0.78) !important;
        font-size: inherit !important;
        font-weight: inherit !important;
        vertical-align: middle !important;
    }
    .gs-member-friends-wrap table tbody td:first-child {
        border-left: 1px solid rgba(99, 102, 241, 0.3) !important;
    }
    .gs-member-friends-wrap table tbody td:last-child {
        border-right: 1px solid rgba(99, 102, 241, 0.3) !important;
    }
    .gs-member-friends-wrap table tbody tr + tr td {
        border-top: 1px solid rgba(99, 102, 241, 0.35) !important;
    }
    .gs-member-friends-wrap table tbody td a,
    .gs-member-friends-wrap table tbody td a:hover {
        color: #89C2E0 !important;
        font-size: inherit !important;
        font-weight: inherit !important;
    }
    .gs-member-friends-wrap table tbody td:empty {
        display: table-cell !important;
    }
    </style>

    <div class="gs-member-friends-wrap">

        <div class="gs-member-friends-tabs" role="tablist">
            <button type="button"
                    class="gs-member-friends-tab is-active"
                    data-gs-panel="connections"
                    role="tab"
                    aria-selected="true">
                <?php esc_html_e( 'My Connections', 'gend-society' ); ?>
            </button>
            <button type="button"
                    class="gs-member-friends-tab"
                    data-gs-panel="referral-sales"
                    role="tab"
                    aria-selected="false">
                <?php esc_html_e( 'Referral Sales', 'gend-society' ); ?>
            </button>
            <button type="button"
                    class="gs-member-friends-tab"
                    data-gs-panel="sales-team"
                    role="tab"
                    aria-selected="false">
                <?php esc_html_e( 'Sales Team', 'gend-society' ); ?>
            </button>
            <button type="button"
                    class="gs-member-friends-tab"
                    data-gs-panel="invite"
                    role="tab"
                    aria-selected="false">
                <?php esc_html_e( 'Invite', 'gend-society' ); ?>
            </button>
        </div>

        <div class="gs-member-friends-panel is-active" data-gs-panel="connections" role="tabpanel">
    <?php
}

function gs_member_friends_tabs_close() {
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
        return;
    }
    if ( ! function_exists( 'bp_is_user_friends' ) || ! bp_is_user_friends() ) {
        return;
    }
    if ( function_exists( 'bp_is_current_action' ) && bp_is_current_action( 'requests' ) ) {
        return;
    }
    ?>
        </div><!-- /panel:connections -->

        <div class="gs-member-friends-panel" data-gs-panel="referral-sales" role="tabpanel">
            <?php
            // The full AAS dashboard renders here. The shortcode
            // [affiliate_sales_dashboard] internally has 3 top-level tabs
            // (Referral Sales / Sales Team / Payments & Analytics). We use
            // JS below to (a) hide its tab strip and (b) move the Sales Team
            // and Payments sub-panels OUT of here into our new top-level
            // panels — so the user only sees the Referral Sales sub-tabs
            // (Tracking URLs / Sales / Commission Rates / Linked Apps)
            // beneath the "Your Network is a Lifetime Asset" header.
            if ( function_exists( 'aas_earnings_endpoint_content' ) ) {
                aas_earnings_endpoint_content();
            } else {
                echo '<p class="psoo-pm-empty">' . esc_html__( 'Earnings dashboard not available.', 'gend-society' ) . '</p>';
            }
            ?>
        </div><!-- /panel:referral-sales -->

        <div class="gs-member-friends-panel" data-gs-panel="sales-team" role="tabpanel">
            <!-- populated by JS - sales-team panel relocated here from the AAS dashboard -->
        </div><!-- /panel:sales-team -->

        <div class="gs-member-friends-panel" data-gs-panel="invite" role="tabpanel">
            <?php
            if ( function_exists( 'gs_invite_render_panel' ) ) {
                gs_invite_render_panel();
            } else {
                echo '<p class="psoo-pm-empty">' . esc_html__( 'Invite UI not available.', 'gend-society' ) . '</p>';
            }
            ?>
        </div><!-- /panel:invite -->

    </div><!-- /.gs-member-friends-wrap -->

    <script>
    (function () {
        var wrap = document.currentScript ? document.currentScript.closest('.gs-member-friends-wrap') : null;
        if ( ! wrap ) {
            wrap = document.querySelector('.gs-member-friends-wrap');
        }
        if ( ! wrap ) return;

        var tabs   = wrap.querySelectorAll('.gs-member-friends-tab');
        var panels = wrap.querySelectorAll('.gs-member-friends-panel');

        tabs.forEach( function ( tab ) {
            tab.addEventListener( 'click', function () {
                var target = tab.getAttribute('data-gs-panel');

                tabs.forEach( function ( t ) {
                    t.classList.remove('is-active');
                    t.setAttribute('aria-selected', 'false');
                } );
                panels.forEach( function ( p ) {
                    p.classList.remove('is-active');
                } );

                tab.classList.add('is-active');
                tab.setAttribute('aria-selected', 'true');

                var panel = wrap.querySelector('[data-gs-panel="' + target + '"].gs-member-friends-panel');
                if ( panel ) panel.classList.add('is-active');
            } );
        } );

        // ── Relocate the AAS dashboard's two non-Referral panels ─────────
        // The affiliate sales dashboard shortcode renders 3 top-level
        // panels (referral-sales / sales-team / payments). We want to
        // promote sales-team and payments to OUR top-level tabs and hide
        // the inner tab strip - so the Referral Sales panel only shows
        // the 4 sub-tabs (Tracking URLs / Sales / Commission Rates /
        // Linked Apps) under the Lifetime Asset header.
        function gsRelocateAasPanels () {
            var dashboard = wrap.querySelector('.aas-affiliate-dashboard');
            if ( ! dashboard ) return false;

            // Hide the dashboard's own 3-tab strip
            var innerTabs = dashboard.querySelector('.aas-dashboard-tabs');
            if ( innerTabs ) innerTabs.style.display = 'none';

            // Move the sales-team panel out
            var salesTeamPanel = dashboard.querySelector('[data-aas-panel="sales-team"]');
            var salesTeamHost  = wrap.querySelector('.gs-member-friends-panel[data-gs-panel="sales-team"]');
            if ( salesTeamPanel && salesTeamHost && salesTeamPanel.parentElement !== salesTeamHost ) {
                salesTeamPanel.removeAttribute('hidden');
                salesTeamPanel.setAttribute('aria-hidden', 'false');
                salesTeamPanel.classList.add('is-active');
                salesTeamHost.appendChild( salesTeamPanel );
            }

            // Lift the Referral Performance Pulse + metric grid out of the
            // payments sub-panel and dock them ABOVE the referral-sales sub-tab
            // strip. This replaces the old "Payments & Analytics" top-level tab
            // — those KPIs make more sense visible on the Referral Sales tab
            // landing.
            var referralPanel = dashboard.querySelector('[data-aas-panel="referral-sales"]');
            var subtabsRow    = referralPanel ? referralPanel.querySelector('.aas-dashboard-subtabs') : null;
            var perfPulse     = dashboard.querySelector('.aas-card.aas-analytics-hero');
            var perfGrid      = dashboard.querySelector('.aas-card.aas-metric-grid--analytics');
            if ( referralPanel && subtabsRow ) {
                if ( perfPulse && perfPulse.parentElement !== referralPanel ) {
                    referralPanel.insertBefore( perfPulse, subtabsRow );
                }
                if ( perfGrid && perfGrid.parentElement !== referralPanel ) {
                    referralPanel.insertBefore( perfGrid, subtabsRow );
                }
            }

            return true;
        }

        // Defer the move until AFTER the AAS dashboard JS has wired up its
        // sub-tab click handlers. AAS attaches them in a DOMContentLoaded
        // listener that walks the .aas-affiliate-dashboard container looking
        // for [data-aas-panel] children — so if we move the panels OUT before
        // that walk runs, those panels are skipped and their sub-tabs never
        // get click handlers (the symptom: sub-tabs render but do nothing on
        // click). DOM moves preserve attached listeners, so initializing first
        // and moving second gives us both correct placement and working clicks.
        function gsScheduleRelocate () {
            // setTimeout(0) inside DOMContentLoaded queues us behind AAS's
            // own DOMContentLoaded handler.
            if ( document.readyState === 'loading' ) {
                document.addEventListener( 'DOMContentLoaded', function () {
                    setTimeout( gsRelocateAasPanels, 0 );
                } );
            } else {
                setTimeout( gsRelocateAasPanels, 0 );
            }
        }
        gsScheduleRelocate();
        // Belt + braces: re-run on window load too, in case the dashboard
        // hydrates content lazily after DOMContentLoaded.
        window.addEventListener( 'load', gsRelocateAasPanels );
    }());
    </script>
    <?php
}

// ─── Enqueue wallet assets for the BuddyPress profile wallet tab ──────────────
add_action( 'wp_enqueue_scripts', 'gs_enqueue_wallet_profile_assets', 20 );
function gs_enqueue_wallet_profile_assets() {
    // If we are on a BuddyPress profile and the URL contains member-wallet
    if ( function_exists( 'bp_is_user' ) && bp_is_user() && ( bp_is_current_component( 'member-wallet' ) || strpos( $_SERVER['REQUEST_URI'], '/member-wallet' ) !== false ) ) {
        // Enqueue the frontend wallet assets from reward-programs
        $url = plugins_url( 'reward-programs/' );
        wp_enqueue_style( 'gend-wallet-frontend', $url . 'assets/frontend-wallet.css', [], '2.0.0' );
        wp_enqueue_script( 'gend-wallet-frontend', $url . 'assets/frontend-wallet.js', [ 'jquery' ], '2.0.0', true );
        
        wp_localize_script( 'gend-wallet-frontend', 'GEND_WALLET', [
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'gend_wallet_nonce' ),
            'i18n'  => [
                'exchange_success'   => __( 'Exchange submitted successfully.', 'reward-programs' ),
                'exchange_error'     => __( 'Exchange failed. Please try again.', 'reward-programs' ),
                'transfer_success'   => __( 'Transfer submitted successfully.', 'reward-programs' ),
                'transfer_error'     => __( 'Transfer failed. Please try again.', 'reward-programs' ),
                'withdraw_success'   => __( 'Withdrawal request submitted.', 'reward-programs' ),
                'withdraw_error'     => __( 'Withdrawal request failed. Please try again.', 'reward-programs' ),
                'processing'         => __( 'Processing…', 'reward-programs' ),
                'user_not_found'     => __( 'Member not found.', 'reward-programs' ),
                'close'              => __( 'Close', 'reward-programs' ),
            ],
        ] );
    }
}
