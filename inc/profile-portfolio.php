<?php
/**
 * Portfolio (member /media) — Three-tab interface
 *
 *   1. Content Schedule  — on own profile shows the aas_get_social_poster_modal_markup()
 *                          content (Schedule Your Social Posts); on other
 *                          profiles shows the displayed user's linked social
 *                          platforms.
 *   2. Posts             — WP posts authored by the displayed user, plus a
 *                          search sub-tab covering all gend.me posts.
 *   3. Media             — the existing Youzify Media gallery, relocated
 *                          into this panel via JS (same pattern as the
 *                          AAS-dashboard relocate in the friends page).
 *
 * Wraps with bp_before_member_body / bp_after_member_body, gated to the
 * media component. Mirrors the Connections-page tab pattern.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'bp_before_member_body', 'gs_member_portfolio_tabs_open', 1 );
add_action( 'bp_after_member_body',  'gs_member_portfolio_tabs_close', 99 );

function gs_portfolio_is_target () {
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) return false;
    $slug = function_exists( 'youzify_profile_media_slug' ) ? youzify_profile_media_slug() : 'media';
    return function_exists( 'bp_is_current_component' ) && bp_is_current_component( $slug );
}

function gs_member_portfolio_tabs_open () {
    if ( ! gs_portfolio_is_target() ) return;
    $own = function_exists( 'bp_is_my_profile' ) && bp_is_my_profile();
    ?>
    <div class="gs-portfolio-wrap">
        <div class="gs-portfolio-tabs" role="tablist">
            <button type="button" class="gs-portfolio-tab is-active" data-gs-portfolio-tab="schedule" role="tab" aria-selected="true"><?php esc_html_e( 'Content Schedule', 'gend-society' ); ?></button>
            <button type="button" class="gs-portfolio-tab" data-gs-portfolio-tab="posts" role="tab" aria-selected="false"><?php esc_html_e( 'Posts', 'gend-society' ); ?></button>
            <button type="button" class="gs-portfolio-tab" data-gs-portfolio-tab="media" role="tab" aria-selected="false"><?php esc_html_e( 'Media', 'gend-society' ); ?></button>
        </div>

        <div class="gs-portfolio-panel is-active" data-gs-portfolio-panel="schedule" role="tabpanel">
            <?php gs_portfolio_render_schedule( $own ); ?>
        </div>

        <div class="gs-portfolio-panel" data-gs-portfolio-panel="posts" role="tabpanel">
            <?php gs_portfolio_render_posts(); ?>
        </div>

        <div class="gs-portfolio-panel" data-gs-portfolio-panel="media" role="tabpanel">
    <?php
}

function gs_member_portfolio_tabs_close () {
    if ( ! gs_portfolio_is_target() ) return;
    ?>
        </div><!-- /panel:media (Youzify gallery rendered above) -->
    </div><!-- /.gs-portfolio-wrap -->

    <style>
    /* ── Full-width: collapse Youzify's two-column grid on the media page,
       scoped to body.media so other profile pages stay normal. Mirrors the
       wallet / groups full-width fix we deployed earlier. */
    body.media .youzify-right-sidebar-layout,
    body.media .youzify-left-sidebar-layout {
        display: block !important;
        grid-template-columns: 1fr !important;
        grid-gap: 0 !important;
    }
    body.media .youzify-main-column,
    body.media .youzify-main-column.grid-column {
        width: 100% !important;
        max-width: none !important;
        flex: 0 0 100% !important;
    }
    body.media .gs-portfolio-wrap,
    body.media .youzify-page-main-content,
    body.media .youzify-content {
        max-width: none !important;
        width: 100% !important;
    }
    body.media .youzify-profile-sidebar,
    body.media .youzify-sidebar-column,
    body.media .yz-sidebar-column {
        display: none !important;
    }

    .gs-portfolio-wrap { width:100%; max-width:none; padding-top:18px; }
    .gs-portfolio-tabs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:22px; border-bottom:1px solid rgba(255,255,255,0.08); padding-bottom:0; }
    .gs-portfolio-tab { background:transparent; border:0; border-bottom:2px solid transparent; padding:12px 22px; color:#64748b; font-family:"Inter",sans-serif; font-size:0.78rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; cursor:pointer; transition:color 0.18s, border-color 0.18s; margin-bottom:-1px; }
    .gs-portfolio-tab:hover { color:rgba(255,255,255,0.75); }
    .gs-portfolio-tab.is-active { color:#b608c9; border-bottom-color:#b608c9; }
    .gs-portfolio-panel { display:none; }
    .gs-portfolio-panel.is-active { display:block; }

    /* Posts list */
    .gs-portfolio-posts-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:14px; }
    .gs-portfolio-posts-bar input[type="search"] { flex:1 1 280px; min-width:0; background:rgba(11,14,20,0.7); border:1px solid rgba(255,255,255,0.12); border-radius:10px; padding:10px 14px; color:#f8fafc; font-size:0.9rem; }
    .gs-portfolio-posts-scope { display:inline-flex; gap:4px; padding:3px; background:rgba(11,14,20,0.45); border:1px solid rgba(255,255,255,0.08); border-radius:10px; }
    .gs-portfolio-posts-scope button { background:transparent; border:0; color:#94a3b8; font-size:0.72rem; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; padding:8px 14px; border-radius:8px; cursor:pointer; }
    .gs-portfolio-posts-scope button.is-active { background:#b608c9; color:#fff; }
    .gs-portfolio-posts-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
    .gs-portfolio-post { background:rgba(11,14,20,0.45); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:18px; transition:border-color 0.2s, transform 0.2s; }
    .gs-portfolio-post:hover { border-color:rgba(182,8,201,0.35); transform:translateY(-2px); }
    .gs-portfolio-post-meta { font-size:0.7rem; color:#94a3b8; letter-spacing:0.12em; text-transform:uppercase; margin:0 0 8px; font-weight:600; }
    .gs-portfolio-post-title { color:#f8fafc !important; font-size:1rem; font-weight:700; margin:0 0 8px; line-height:1.3; text-decoration:none; display:block; }
    .gs-portfolio-post-title:hover { color:#cc1ee1 !important; }
    .gs-portfolio-post-excerpt { color:#cbd5e1; font-size:0.85rem; line-height:1.55; margin:0; }
    .gs-portfolio-empty { color:#64748b; text-align:center; padding:32px 0; font-size:0.9rem; }

    /* Linked social platforms (other-profile schedule view) */
    .gs-portfolio-socials { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px; }
    .gs-portfolio-social { background:rgba(11,14,20,0.45); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:18px; }
    .gs-portfolio-social-name { color:#f8fafc !important; font-weight:700; text-decoration:none; display:flex; align-items:center; gap:10px; margin-bottom:8px; }
    .gs-portfolio-social-handle { color:#89C2E0; font-size:0.85rem; word-break:break-all; }
    .gs-portfolio-social-recent { color:#64748b; font-size:0.78rem; margin-top:10px; font-style:italic; }
    </style>

    <script>
    (function () {
        var wrap = document.querySelector('.gs-portfolio-wrap');
        if (!wrap) return;

        // Tab switching
        var tabs   = wrap.querySelectorAll('.gs-portfolio-tab');
        var panels = wrap.querySelectorAll('.gs-portfolio-panel');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var target = tab.getAttribute('data-gs-portfolio-tab');
                tabs.forEach(function (t) { t.classList.remove('is-active'); t.setAttribute('aria-selected','false'); });
                panels.forEach(function (p) { p.classList.remove('is-active'); });
                tab.classList.add('is-active'); tab.setAttribute('aria-selected','true');
                var p = wrap.querySelector('[data-gs-portfolio-panel="' + target + '"]');
                if (p) p.classList.add('is-active');
            });
        });

        // Move the existing Youzify media gallery (#youzify-media) INTO our
        // Media panel. The Media tabs subnav (.youzify-media-tabs / etc.)
        // already lives inside it, so the user keeps the All/Photos/Videos
        // controls.
        var mediaPanel = wrap.querySelector('[data-gs-portfolio-panel="media"]');
        function relocateMedia () {
            if (!mediaPanel) return;
            var src = document.querySelector('#youzify-media, .youzify-page-main-content > .youzify-media, .youzify-media-content');
            if (src && src.parentElement !== mediaPanel) {
                mediaPanel.appendChild(src);
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { setTimeout(relocateMedia, 0); });
        } else {
            setTimeout(relocateMedia, 0);
        }
        window.addEventListener('load', relocateMedia);

        // Posts: scope toggle (mine / all) + search filter
        var postsRoot = wrap.querySelector('[data-gs-portfolio-panel="posts"]');
        if (postsRoot) {
            var scopeBtns  = postsRoot.querySelectorAll('[data-gs-posts-scope]');
            var searchInput= postsRoot.querySelector('[data-gs-posts-search]');
            var grids      = postsRoot.querySelectorAll('[data-gs-posts-grid]');
            scopeBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var scope = btn.getAttribute('data-gs-posts-scope');
                    scopeBtns.forEach(function (b) { b.classList.remove('is-active'); });
                    btn.classList.add('is-active');
                    grids.forEach(function (g) { g.style.display = ( g.getAttribute('data-gs-posts-grid') === scope ) ? '' : 'none'; });
                });
            });
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    var q = (searchInput.value || '').toLowerCase().trim();
                    grids.forEach(function (g) {
                        g.querySelectorAll('.gs-portfolio-post').forEach(function (p) {
                            var hay = (p.getAttribute('data-search') || '').toLowerCase();
                            p.style.display = ( !q || hay.indexOf(q) !== -1 ) ? '' : 'none';
                        });
                    });
                });
            }
        }
    }());
    </script>
    <?php
}

/**
 * Content Schedule panel content.
 *
 * On own profile: render the AAS social-poster modal HTML (Schedule Your
 * Social Posts) inline. The function emits the modal markup; we add CSS to
 * present it as inline content rather than a hidden popup.
 *
 * On another member's profile: render the displayed user's linked social
 * networks (xprofile "Social Networks" field) as a card grid, with a
 * placeholder hint for recent-posts integration.
 */
function gs_portfolio_render_schedule ( $own_profile ) {
    if ( $own_profile ) {
        if ( function_exists( 'aas_get_social_poster_modal_markup' ) ) {
            // The modal's actual root is <div id="aas-social-poster-modal"
            // class="aas-modal-overlay" style="display:none;">. Override the
            // inline display:none + the overlay's fixed positioning so its
            // contents render inline inside our panel.
            echo '<style>'
                . '.gs-portfolio-panel #aas-social-poster-modal,'
                . '.gs-portfolio-panel .aas-modal-overlay {'
                . '  display: block !important;'
                . '  position: static !important; inset: auto !important;'
                . '  opacity: 1 !important; visibility: visible !important;'
                . '  pointer-events: auto !important;'
                . '  background: transparent !important; backdrop-filter: none !important;'
                . '  z-index: auto !important;'
                . '}'
                . '.gs-portfolio-panel #aas-social-poster-modal .aas-modal,'
                . '.gs-portfolio-panel .aas-modal-overlay .aas-modal,'
                . '.gs-portfolio-panel #aas-social-poster-modal .aas-modal-content {'
                . '  position: static !important; transform: none !important;'
                . '  max-width: none !important; width: 100% !important;'
                . '  height: auto !important;'
                . '  margin: 0 !important; max-height: none !important;'
                . '  box-shadow: none !important;'
                . '}'
                . '.gs-portfolio-panel .aas-modal-close,'
                . '.gs-portfolio-panel #aas-social-poster-modal-close { display: none !important; }'
                /* Hide the modal header ("Social Post Scheduler / Manage your
                   social channels…") — redundant when rendered inline inside
                   the Content Schedule tab. */
                . '.gs-portfolio-panel #aas-social-poster-modal .aas-modal-title-group { display: none !important; }'
                . '.gs-portfolio-panel #aas-social-poster-modal .aas-modal-header {'
                . '  border-bottom: 0 !important; padding: 0 !important;'
                . '}'
                . '</style>';
            // The function emits markup that has style="display:none;" on
            // the overlay. Strip that single inline style so even browsers
            // ignoring our CSS reveal the content.
            $markup = aas_get_social_poster_modal_markup();
            $markup = preg_replace( '/(id="aas-social-poster-modal"[^>]*)\s*style="display:\s*none;?\s*"/i', '$1', $markup );
            echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput

            // Reorder + rename the modal's sub-tabs:
            //   Settings & Channels → "Accounts" + first position + active on load.
            // Run after a microtask + on load so AAS's own init has wired the
            // panel-switching listeners first; we then click the button so the
            // active panel updates to match.
            ?>
            <script>
            (function () {
                function tweakAasTabs () {
                    var modal = document.getElementById('aas-social-poster-modal');
                    if (!modal || modal.dataset.gsTabsTweaked) return;
                    var tabsBar = modal.querySelector('.aas-modal-tabs');
                    var channelsBtn = modal.querySelector('.aas-modal-tabs [data-tab="channels"]');
                    if (!tabsBar || !channelsBtn) return;

                    channelsBtn.textContent = 'Accounts';
                    tabsBar.insertBefore(channelsBtn, tabsBar.firstChild);

                    // Activate Accounts tab on initial load. Click the button so
                    // AAS's own handler swaps panels and updates is-active state.
                    try { channelsBtn.click(); } catch (e) {}
                    modal.dataset.gsTabsTweaked = '1';
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function () { setTimeout(tweakAasTabs, 0); });
                } else {
                    setTimeout(tweakAasTabs, 0);
                }
                window.addEventListener('load', tweakAasTabs);
            }());
            </script>
            <?php
        } else {
            echo '<p class="gs-portfolio-empty">' . esc_html__( 'Schedule UI not available — the sales-team plugin is required.', 'gend-society' ) . '</p>';
        }
        return;
    }

    // Other member's profile — list their linked social platforms. Pulls
    // from xprofile field "Social Networks" (Youzify's standard field) by
    // walking xprofile data and collecting URL/value pairs.
    $user_id = function_exists( 'bp_displayed_user_id' ) ? (int) bp_displayed_user_id() : 0;
    $links   = gs_portfolio_collect_social_links( $user_id );
    if ( empty( $links ) ) {
        echo '<p class="gs-portfolio-empty">' . esc_html__( 'This member has no linked social platforms yet.', 'gend-society' ) . '</p>';
        return;
    }
    echo '<div class="gs-portfolio-socials">';
    foreach ( $links as $link ) {
        echo '<div class="gs-portfolio-social">';
        echo '<a class="gs-portfolio-social-name" href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener noreferrer">';
        echo esc_html( $link['label'] ) . '</a>';
        echo '<div class="gs-portfolio-social-handle">' . esc_html( $link['url'] ) . '</div>';
        echo '<div class="gs-portfolio-social-recent">' . esc_html__( 'Recent posts will appear here once the platform integration is enabled.', 'gend-society' ) . '</div>';
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Walk the displayed user's xprofile data + user_meta and collect anything
 * that looks like a social-platform URL. Returns array of { label, url }.
 */
function gs_portfolio_collect_social_links ( $user_id ) {
    if ( ! $user_id ) return array();
    $platforms = array(
        'twitter'   => array( 'X / Twitter',  array( 'twitter.com', 'x.com' ) ),
        'linkedin'  => array( 'LinkedIn',     array( 'linkedin.com' ) ),
        'instagram' => array( 'Instagram',    array( 'instagram.com' ) ),
        'facebook'  => array( 'Facebook',     array( 'facebook.com', 'fb.com' ) ),
        'youtube'   => array( 'YouTube',      array( 'youtube.com', 'youtu.be' ) ),
        'tiktok'    => array( 'TikTok',       array( 'tiktok.com' ) ),
        'github'    => array( 'GitHub',       array( 'github.com' ) ),
        'website'   => array( 'Website',      array() ), // catch-all
    );

    $values = array();
    // Walk all xprofile fields for this user.
    if ( function_exists( 'bp_get_user_meta' ) && function_exists( 'BP_XProfile_ProfileData' ) ) {
        // Best-effort — xprofile data isn't easily enumerable via a single
        // function, so collect from common Youzify social fields.
    }
    // Pull standard Youzify social meta keys + xprofile by groups.
    if ( function_exists( 'bp_xprofile_get_groups' ) ) {
        $groups = bp_xprofile_get_groups( array( 'fetch_fields' => true, 'fetch_field_data' => true, 'user_id' => $user_id ) );
        if ( is_array( $groups ) ) {
            foreach ( $groups as $g ) {
                if ( empty( $g->fields ) ) continue;
                foreach ( $g->fields as $f ) {
                    $val = method_exists( $f, 'get_value' ) ? $f->get_value() : ( $f->data->value ?? '' );
                    if ( ! is_string( $val ) || ! $val ) continue;
                    // xprofile social-network field returns a serialized
                    // array of { name, value } pairs in Youzify.
                    $maybe = maybe_unserialize( $val );
                    if ( is_array( $maybe ) ) {
                        foreach ( $maybe as $entry ) {
                            if ( is_array( $entry ) && ! empty( $entry['value'] ) && filter_var( $entry['value'], FILTER_VALIDATE_URL ) ) {
                                $values[] = $entry['value'];
                            } elseif ( is_string( $entry ) && filter_var( $entry, FILTER_VALIDATE_URL ) ) {
                                $values[] = $entry;
                            }
                        }
                    } elseif ( filter_var( $val, FILTER_VALIDATE_URL ) ) {
                        $values[] = $val;
                    }
                }
            }
        }
    }

    $links = array();
    $seen  = array();
    foreach ( array_unique( $values ) as $url ) {
        $host = strtolower( parse_url( $url, PHP_URL_HOST ) ?: '' );
        if ( ! $host || isset( $seen[ $url ] ) ) continue;
        $seen[ $url ] = true;
        $label = '';
        foreach ( $platforms as $key => $p ) {
            list( $name, $hosts ) = $p;
            foreach ( $hosts as $h ) {
                if ( strpos( $host, $h ) !== false ) { $label = $name; break 2; }
            }
        }
        if ( ! $label ) $label = ucfirst( str_replace( 'www.', '', $host ) );
        $links[] = array( 'label' => $label, 'url' => $url );
    }
    return $links;
}

/**
 * Posts panel — list of WP posts authored by the displayed user, plus a
 * search-and-filter toggle that pivots the same panel to "All gend.me posts".
 */
function gs_portfolio_render_posts () {
    $user_id = function_exists( 'bp_displayed_user_id' ) ? (int) bp_displayed_user_id() : 0;

    $mine = $user_id ? new WP_Query( array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'author'         => $user_id,
        'posts_per_page' => 24,
        'no_found_rows'  => true,
    ) ) : null;
    $all  = new WP_Query( array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 60,
        'no_found_rows'  => true,
    ) );
    ?>
    <div class="gs-portfolio-posts-bar">
        <input type="search" placeholder="<?php esc_attr_e( 'Search posts…', 'gend-society' ); ?>" data-gs-posts-search />
        <div class="gs-portfolio-posts-scope" role="tablist">
            <button type="button" class="is-active" data-gs-posts-scope="mine"><?php esc_html_e( 'This Author', 'gend-society' ); ?></button>
            <button type="button" data-gs-posts-scope="all"><?php esc_html_e( 'All gend.me Posts', 'gend-society' ); ?></button>
        </div>
    </div>

    <div data-gs-posts-grid="mine" class="gs-portfolio-posts-grid">
        <?php gs_portfolio_render_post_grid( $mine, 'mine' ); ?>
    </div>
    <div data-gs-posts-grid="all" class="gs-portfolio-posts-grid" style="display:none;">
        <?php gs_portfolio_render_post_grid( $all, 'all' ); ?>
    </div>
    <?php
    if ( $mine ) wp_reset_postdata();
    wp_reset_postdata();
}

function gs_portfolio_render_post_grid ( $q, $scope ) {
    if ( ! $q || ! $q->have_posts() ) {
        $msg = ( $scope === 'mine' )
            ? __( 'No posts authored yet.', 'gend-society' )
            : __( 'No posts found.', 'gend-society' );
        echo '<p class="gs-portfolio-empty">' . esc_html( $msg ) . '</p>';
        return;
    }
    while ( $q->have_posts() ) {
        $q->the_post();
        $title  = get_the_title();
        $excerpt= wp_strip_all_tags( get_the_excerpt() );
        $author = get_the_author();
        $date   = get_the_date();
        $search = $title . ' ' . $excerpt . ' ' . $author;
        ?>
        <article class="gs-portfolio-post" data-search="<?php echo esc_attr( $search ); ?>">
            <p class="gs-portfolio-post-meta"><?php echo esc_html( $date ); ?><?php if ( $scope === 'all' ) echo ' · ' . esc_html( $author ); ?></p>
            <a class="gs-portfolio-post-title" href="<?php the_permalink(); ?>"><?php echo esc_html( $title ); ?></a>
            <?php if ( $excerpt ) : ?>
                <p class="gs-portfolio-post-excerpt"><?php echo esc_html( wp_trim_words( $excerpt, 24, '…' ) ); ?></p>
            <?php endif; ?>
        </article>
        <?php
    }
}
