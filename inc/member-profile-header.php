<?php
/**
 * Member Profile Header
 *
 * Replaces the Youzify member profile header + navbar on BP member pages with
 * the GenD terminal-style design: kinetic-border identity port, metrics grid,
 * and a live tab nav bar sourced from Youzify's primary nav.
 *
 * Balance data is filterable via `gdc_profile_header_balances` so other
 * plugins can swap in real meta keys without touching this file.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Global Youzify dark-terminal styles (all BP pages) ───────────────────────

add_action( 'wp_enqueue_scripts', 'gdc_enqueue_global_youzify_styles' );
function gdc_enqueue_global_youzify_styles() {
    if ( ! function_exists( 'is_buddypress' ) || ! is_buddypress() ) return;
    wp_register_style( 'gdc-youzify-global', false, [], GS_VERSION );
    wp_enqueue_style( 'gdc-youzify-global' );
    wp_add_inline_style( 'gdc-youzify-global', gdc_global_youzify_css() );
}

// ─── Profile-page-only styles (header component + cover) ─────────────────────

add_action( 'wp_enqueue_scripts', 'gdc_enqueue_profile_header_styles' );
function gdc_enqueue_profile_header_styles() {
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) return;
    wp_register_style( 'gdc-profile-header', false, [], GS_VERSION );
    wp_enqueue_style( 'gdc-profile-header' );
    wp_add_inline_style( 'gdc-profile-header', gdc_profile_header_css() );
}

// ─── Render header ────────────────────────────────────────────────────────────
// Hook into youzify_profile_before_header (fires before the <header> element)
// so our section renders first. The original header + navbar are hidden via CSS.

add_action( 'youzify_profile_before_header', 'gdc_render_profile_header', 1 );
function gdc_render_profile_header() {
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) return;

    $user_id        = (int) bp_displayed_user_id();
    $current_id     = (int) get_current_user_id();
    $is_own_profile = ( $current_id > 0 && $current_id === $user_id );

    // ── Identity ──────────────────────────────────────────────────────────────
    $avatar_url   = bp_core_fetch_avatar( [
        'item_id' => $user_id,
        'type'    => 'full',
        'html'    => false,
    ] );
    $display_name = bp_get_displayed_user_fullname();

    $member_type     = function_exists( 'bp_get_member_type' ) ? bp_get_member_type( $user_id ) : false;
    $member_type_obj = ( $member_type && function_exists( 'bp_get_member_type_object' ) )
                        ? bp_get_member_type_object( $member_type ) : null;
    $auth_label      = $member_type_obj
                        ? strtoupper( $member_type_obj->labels['singular_name'] )
                        : 'MEMBER';

    // ── Live balance data ─────────────────────────────────────────────────────
    $has_mycred = function_exists( 'mycred_get_users_balance' );

    $task_credits = $has_mycred
        ? (int) mycred_get_users_balance( $user_id, 'tasks' )
        : 0;

    $ai_tokens = round( (float) get_user_meta( $user_id, 'aipa_credits', true ), 1 );

    $dgen_balance = $has_mycred
        ? (int) mycred_get_users_balance( $user_id, 'transact' )
        : 0;

    $store_credits = $has_mycred
        ? (float) mycred_get_users_balance( $user_id, 'mycred_default' )
        : 0.0;

    $balances = apply_filters( 'gdc_profile_header_balances', [
        [
            'label'   => 'Task Credits',
            'value'   => number_format( $task_credits ),
            'color'   => 'var(--gph-magenta)',
            'stagger' => 2,
        ],
        [
            'label'   => 'AI Builder Tokens',
            'value'   => number_format( $ai_tokens, 1 ),
            'color'   => 'var(--gph-blue)',
            'stagger' => 3,
        ],
        [
            'label'   => '🇨🇦 DGEN Balance',
            'value'   => number_format( $dgen_balance ),
            'color'   => 'var(--gph-green)',
            'stagger' => 4,
        ],
        [
            'label'   => '🇨🇦 Store Credits',
            'value'   => '$' . number_format( $store_credits, 2 ),
            'color'   => 'var(--gph-red)',
            'stagger' => 5,
        ],
    ], $user_id );

    // ── Linked application row ────────────────────────────────────────────────
    $memberships_url = home_url( '/my-account/memberships/' );
    $linked_app = apply_filters( 'gdc_profile_linked_app', [
        'label' => get_user_meta( $user_id, '_gdc_linked_app_name', true ) ?: 'LINKED WEB APPLICATION',
        'id'    => get_user_meta( $user_id, '_gdc_member_id', true )
                    ?: ( '#GEN-' . str_pad( $user_id, 4, '0', STR_PAD_LEFT ) ),
        'url'   => $memberships_url,
    ], $user_id );

    // ── Most recent admin group ───────────────────────────────────────────────
    // Shows the most recently active group where the user is admin.
    // "View Site" uses the group's psoo linked app URL if set, else memberships.
    $admin_group        = null;
    $admin_group_app    = '';
    $admin_group_avatar = '';
    $admin_group_url    = '';

    if ( function_exists( 'groups_get_groups' ) && function_exists( 'groups_is_user_admin' ) ) {
        $result = groups_get_groups( [
            'user_id'     => $user_id,
            'show_hidden' => true,
            'per_page'    => 30,
            'orderby'     => 'last_activity',
            'order'       => 'DESC',
        ] );
        if ( ! empty( $result['groups'] ) ) {
            foreach ( $result['groups'] as $grp ) {
                if ( ! groups_is_user_admin( $user_id, $grp->id ) ) continue;
                $admin_group        = $grp;
                $admin_group_app    = ( function_exists( 'psoo_get_group_app_url' ) && psoo_get_group_app_url( $grp->id ) )
                                        ? psoo_get_group_app_url( $grp->id )
                                        : $memberships_url;
                $admin_group_avatar = bp_core_fetch_avatar( [
                    'item_id' => $grp->id,
                    'object'  => 'group',
                    'type'    => 'thumb',
                    'html'    => false,
                ] );
                $admin_group_url = function_exists( 'bp_get_group_permalink' )
                    ? bp_get_group_permalink( $grp )
                    : home_url( trailingslashit( bp_get_groups_root_slug() . '/' . $grp->slug ) );
                break;
            }
        }
    }

    // ── Action buttons ────────────────────────────────────────────────────────
    $msg_url     = '';
    $friend_text = '';
    $friend_url  = '';
    if ( ! $is_own_profile && $current_id > 0 ) {
        if ( bp_is_active( 'messages' ) ) {
            $msg_url = bp_loggedin_user_domain()
                . bp_get_messages_slug()
                . '/compose/?r=' . bp_get_displayed_user_username();
        }
        if ( bp_is_active( 'friends' ) && function_exists( 'friends_check_friendship_status' ) ) {
            $status      = friends_check_friendship_status( $current_id, $user_id );
            $friend_url  = bp_displayed_user_domain();
            $friend_text = ( 'is_friend' === $status ) ? 'Connected'
                         : ( ( 'pending' === $status ) ? 'Pending' : '+ Connect' );
        }
    }

    // ── Nav ───────────────────────────────────────────────────────────────────
    $nav_items         = function_exists( 'youzify_get_profile_primary_nav' )
                          ? (array) youzify_get_profile_primary_nav() : [];

    // Apply requested society stylings modifications to the nav array
    $files_item  = null;
    $files_index = -1;

    foreach ( $nav_items as $index => $item ) {
        // Change "Groups" to "App Projects"
        if ( $item->slug === 'groups' && strpos( $item->name, 'App Projects' ) === false ) {
            $item->name = str_replace( 'Groups', 'App Projects', $item->name );
        }

        // Change "Files" to "Portfolio"
        if ( ( $item->slug === 'files' || $item->slug === 'bp-files' || strpos( strip_tags( $item->name ), 'Files' ) !== false ) && strpos( $item->name, 'Portfolio' ) === false ) {
            $item->name = str_replace( 'Files', 'Portfolio', $item->name );
            $files_item  = $item;
            $files_index = $index;
        }
    }

    // Remove "Society"
    foreach ( $nav_items as $index => $item ) {
        if ( $item->slug === 'society' || strpos( strip_tags( $item->name ), 'Society' ) !== false ) {
            unset( $nav_items[ $index ] );
        }
    }

    if ( $files_item && $files_index !== -1 && isset( $nav_items[ $files_index ] ) ) {
        unset( $nav_items[ $files_index ] );
        $nav_items = array_values( $nav_items ); // Re-index
        array_splice( $nav_items, 1, 0, [ $files_item ] );
    } else {
        $nav_items = array_values( $nav_items ); // Re-index
    }

    // ── Re-add Messages & Settings — Youzify hides these via youzify_profile_hidden_tabs()
    // but our custom header needs them. Pull them back from the raw BP nav.
    $slugs_already = array_column( array_map( 'get_object_vars', $nav_items ), 'slug' );
    if ( isset( buddypress()->members ) && is_object( buddypress()->members->nav ) ) {
        $raw_nav = buddypress()->members->nav->get_primary();
        foreach ( [ 'messages', 'settings' ] as $restore_slug ) {
            if ( in_array( $restore_slug, $slugs_already, true ) ) {
                continue; // already present
            }
            foreach ( $raw_nav as $raw_item ) {
                if ( $raw_item['slug'] !== $restore_slug ) {
                    continue;
                }
                // Respect show_for_displayed_user — only show on own profile if not set
                if ( empty( $raw_item['show_for_displayed_user'] ) && ! bp_is_my_profile() ) {
                    break;
                }
                // Cast to object to match the rest of $nav_items
                $nav_items[] = (object) [
                    'name' => $raw_item['name'],
                    'slug' => $raw_item['slug'],
                    'link' => $raw_item['link'],
                ];
                break;
            }
        }
    }


    $current_component = bp_current_component();

    // ── Cover photo ───────────────────────────────────────────────────────────
    // Use a real <div> with inline background-image — CSS custom properties
    // are unreliable as url() carriers across browsers.
    $cover_url = function_exists( 'bp_attachments_get_attachment' )
        ? bp_attachments_get_attachment( 'url', [ 'object_dir' => 'members', 'item_id' => $user_id ] )
        : '';

    // Fallback to Youzify's configured default cover image
    if ( ! $cover_url && function_exists( 'youzify_option' ) ) {
        $cover_url = youzify_option( 'youzify_default_profiles_cover', '' );
    }

    // Final fallback: Youzify's built-in pattern cover (always exists)
    if ( ! $cover_url && function_exists( 'youzify_get_default_profile_cover' ) ) {
        $cover_url = youzify_get_default_profile_cover();
    }

    ?>
    <?php if ( $cover_url ) : ?>
    <style>.gdc-profile-uplink::before { background-image: url("<?php echo esc_url( $cover_url ); ?>"); }</style>
    <?php endif; ?>
    <section class="gdc-profile-uplink">

        <div class="gdc-profile-hub">

            <!-- ── 1. Identity Port ───────────────────────────────────── -->
            <div class="gdc-identity-wrap gdc-stagger-1">
                <div class="gdc-kbx" style="--kbx-color: var(--gph-magenta)">
                    <div class="gdc-identity-inner">
                        <img src="<?php echo esc_url( $avatar_url ); ?>"
                             alt="<?php echo esc_attr( $display_name ); ?>"
                             class="gdc-avatar">
                        <h1 class="gdc-identity-name"><?php echo esc_html( $display_name ); ?></h1>
                        <p class="gdc-identity-auth">AUTHORIZATION: <?php echo esc_html( $auth_label ); ?></p>
                        <?php if ( $msg_url || $friend_text ) : ?>
                        <div class="gdc-identity-actions">
                            <?php if ( $msg_url ) : ?>
                            <a href="<?php echo esc_url( $msg_url ); ?>" class="gdc-action-btn">Message</a>
                            <?php endif; ?>
                            <?php if ( $friend_text ) : ?>
                            <a href="<?php echo esc_url( $friend_url ); ?>" class="gdc-action-btn gdc-action-btn--connect"><?php echo esc_html( $friend_text ); ?></a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── 2. Metrics Port ────────────────────────────────────── -->
            <div class="gdc-metrics-port">

                <!-- Linked app row -->
                <div class="gdc-kbx gdc-stagger-2" style="--kbx-color: var(--gph-blue)">
                    <a href="<?php echo esc_url( $linked_app['url'] ); ?>" class="gdc-linked-app-row">
                        <span class="gdc-linked-app-label"><?php echo esc_html( $linked_app['label'] ); ?></span>
                        <span class="gdc-linked-app-id"><?php echo esc_html( $linked_app['id'] ); ?></span>
                    </a>
                </div>

                <?php if ( $admin_group ) : ?>
                <!-- Most recent admin group with a linked app -->
                <div class="gdc-kbx gdc-stagger-3" style="--kbx-color: var(--gph-magenta)">
                    <div class="gdc-admin-group-row">
                        <?php if ( $admin_group_avatar ) : ?>
                        <img src="<?php echo esc_url( $admin_group_avatar ); ?>"
                             alt="<?php echo esc_attr( $admin_group->name ); ?>"
                             class="gdc-admin-group-avatar">
                        <?php endif; ?>
                        <div class="gdc-admin-group-info">
                            <a href="<?php echo esc_url( $admin_group_url ); ?>"
                               class="gdc-admin-group-name"><?php echo esc_html( $admin_group->name ); ?></a>
                            <span class="gdc-admin-group-role">Group Admin</span>
                        </div>
                        <a href="<?php echo esc_url( $admin_group_app ); ?>"
                           class="gdc-action-btn gdc-view-site-btn"
                           target="_blank" rel="noopener">View Site</a>
                        <?php
                        // Details button — surfaced when the viewer is a
                        // logged-in customer who's also the group admin
                        // (i.e., the membership owner from this view's
                        // perspective). Routes them to /my-account/memberships/
                        // where the membership-detail popup with all three
                        // tabs (Orders / Domain / Backups) lives. Cross-page
                        // deep-link rather than rendering the popup inline
                        // because the membership modal HTML is only emitted
                        // on the my-account endpoint render.
                        $current_uid = get_current_user_id();
                        $is_admin_viewing_own_admin_group = $current_uid && $admin_group && isset($admin_group->creator_id) && (int) $admin_group->creator_id === $current_uid;
                        if ($is_admin_viewing_own_admin_group && function_exists('wc_get_account_endpoint_url')) {
                          $details_url = wc_get_account_endpoint_url('memberships');
                          ?>
                          <a href="<?php echo esc_url( $details_url ); ?>"
                             class="gdc-action-btn gdc-details-btn"
                             style="margin-left:8px;background:rgba(120,87,255,.18);color:#c4b5fd;border:1px solid rgba(167,139,250,.35);">Details</a>
                          <?php
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Balance grid -->
                <div class="gdc-balance-grid">
                    <?php foreach ( $balances as $b ) : ?>
                    <div class="gdc-kbx gdc-stagger-<?php echo (int) $b['stagger']; ?>"
                         style="--kbx-color: <?php echo esc_attr( $b['color'] ); ?>">
                        <div class="gdc-node-content">
                            <div class="gdc-node-label"><?php echo esc_html( $b['label'] ); ?></div>
                            <div class="gdc-node-value" style="color: <?php echo esc_attr( $b['color'] ); ?>">
                                <?php echo esc_html( $b['value'] ); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div><!-- .gdc-metrics-port -->

        </div><!-- .gdc-profile-hub -->

        <!-- ── 3. Nav Bar ────────────────────────────────────────────── -->
        <nav class="gdc-profile-nav" id="gdc-profile-nav" aria-label="Profile navigation">
            <div class="gdc-profile-nav-inner">
                <?php foreach ( $nav_items as $item ) :
                    $is_active = ( $current_component === $item->slug );
                ?>
                <a href="<?php echo esc_url( $item->link ); ?>"
                   class="gdc-nav-item<?php echo $is_active ? ' gdc-nav-item--active' : ''; ?>">
                    <?php echo wp_kses( $item->name, [ 'span' => [ 'class' => true ] ] ); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </nav>

    </section>
    <?php
}

// ─── CSS ──────────────────────────────────────────────────────────────────────

function gdc_profile_header_css() {
    return '
/* ── Hide original Youzify header + navbar on member profile pages ────── */
.youzify.youzify-profile #youzify-profile-header,
.youzify.youzify-profile #youzify-profile-navmenu,
.youzify.youzify-profile .youzify-profile-navmenu,
.youzify.youzify-profile .youzify-open-nav {
    display: none !important;
}

/* ── Design tokens scoped to our header ──────────────────────────────── */
.gdc-profile-uplink {
    --gph-bg:      #0b0e14;
    --gph-glass:   rgba(255,255,255,0.03);
    --gph-border:  rgba(255,255,255,0.1);
    --gph-green:   #00ff88;
    --gph-blue:    #89C2E0;
    --gph-magenta: #b608c9;
    --gph-red:     #cc0000;
}

/* ── Section wrapper ─────────────────────────────────────────────────── */
.gdc-profile-uplink {
    background: var(--gph-bg);
    font-family: "Inter", sans-serif;
    color: #fff;
    padding: 80px 20px 0;
    position: relative;
    overflow: hidden;
    isolation: isolate;
}

/* ── Cover photo background ──────────────────────────────────────────── */
/* ::before = blurred cover image (background-image injected via <style>) */
/* ::after  = dark gradient scrim for legibility                          */
.gdc-profile-uplink::before {
    content: "";
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center top;
    filter: blur(14px) brightness(0.35) saturate(1.3);
    transform: scale(1.06); /* hides blur fringing at edges */
    z-index: -2;
    pointer-events: none;
}
.gdc-profile-uplink::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(
        to bottom,
        rgba(11,14,20,0.40) 0%,
        rgba(11,14,20,0.68) 55%,
        rgba(11,14,20,0.92) 100%
    );
    z-index: -1;
    pointer-events: none;
}

/* ── Two-column layout ───────────────────────────────────────────────── */
.gdc-profile-hub {
    max-width: 1400px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 40px;
    perspective: 2000px;
}
@media (max-width: 1100px) {
    .gdc-profile-hub { grid-template-columns: 1fr; }
    .gdc-balance-grid { grid-template-columns: 1fr 1fr; }
}

/* ══ KINETIC BORDER BOX ══════════════════════════════════════════════════
   Creates the rotating conic-gradient border effect.
   ::before  = the spinning light track
   ::after   = the dark glass mask that sits over it, leaving a 1-2px rim
   ═══════════════════════════════════════════════════════════════════════ */
.gdc-kbx {
    position: relative;
    background: var(--gph-bg);
    border-radius: 30px;
    z-index: 1;
    overflow: hidden;
    padding: 1px;
}
/* Spinning light track */
.gdc-kbx::before {
    content: "";
    position: absolute;
    z-index: -1;
    inset: -50%;
    background: conic-gradient(
        from 0deg,
        transparent 0%,
        transparent 25%,
        var(--kbx-color, var(--gph-blue)) 50%,
        transparent 75%,
        transparent 100%
    );
    animation: gdcBorderScan 4s linear infinite;
}
/* Glass mask — covers ::before leaving a 1-2 px glowing rim */
.gdc-kbx::after {
    content: "";
    position: absolute;
    inset: 2px;
    background: var(--gph-bg);
    border-radius: 28px;
    z-index: -1;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
}
@keyframes gdcBorderScan {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

/* ══ 1. IDENTITY PORT ════════════════════════════════════════════════════
   3D entrance: swings in from the left.
   ═══════════════════════════════════════════════════════════════════════ */
.gdc-identity-wrap {
    opacity: 0;
    transform: rotateY(-20deg) translateX(-50px);
    animation: gdcPortEnter 1s cubic-bezier(0.16,1,0.3,1) forwards;
}
@keyframes gdcPortEnter {
    to { opacity: 1; transform: rotateY(0) translateX(0); }
}
/* The inner card inside the kinetic box */
.gdc-identity-inner {
    padding: 40px 20px;
    text-align: center;
    background: rgba(255,255,255,0.02);
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    box-sizing: border-box;
    border-radius: 28px;
}
.gdc-avatar {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    border: 2px solid var(--gph-magenta);
    margin-bottom: 20px;
    box-shadow: 0 0 30px rgba(182,8,201,0.2);
    object-fit: cover;
}
.gdc-identity-name {
    margin: 0;
    font-size: 1.6rem;
    font-weight: 900;
    color: #fff;
}
.gdc-identity-auth {
    color: var(--gph-blue);
    font-family: monospace;
    font-size: 0.7rem;
    letter-spacing: 2px;
    margin: 6px 0 0;
}
.gdc-identity-actions {
    display: flex;
    gap: 10px;
    margin-top: 30px;
    flex-wrap: wrap;
    justify-content: center;
}
.gdc-action-btn {
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--gph-border);
    color: #fff !important;
    padding: 10px 20px;
    border-radius: 12px;
    text-decoration: none !important;
    font-size: 0.7rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 1px;
    transition: border-color 0.3s, background 0.3s;
}
.gdc-action-btn:hover {
    border-color: var(--gph-green);
    background: rgba(0,255,136,0.1);
}
.gdc-action-btn--connect:hover {
    border-color: var(--gph-blue);
    background: rgba(137,194,224,0.1);
}

/* ══ 2. METRICS PORT — kinetic boxes have staggered slide-up entrances ═══
   ═══════════════════════════════════════════════════════════════════════ */
.gdc-metrics-port {
    display: flex;
    flex-direction: column;
    gap: 25px;
}
/* Entrance animation applied to the kinetic boxes in the metrics column */
.gdc-metrics-port .gdc-kbx,
.gdc-balance-grid .gdc-kbx {
    opacity: 0;
    transform: translateY(24px);
    animation: gdcKbxEnter 0.7s cubic-bezier(0.22,1,0.36,1) forwards;
}
@keyframes gdcKbxEnter {
    to { opacity: 1; transform: none; }
}

/* Stagger delays — applied to any element that needs them */
.gdc-stagger-1 { animation-delay: 0.2s; }
.gdc-stagger-2 { animation-delay: 0.4s; }
.gdc-stagger-3 { animation-delay: 0.6s; }
.gdc-stagger-4 { animation-delay: 0.8s; }
.gdc-stagger-5 { animation-delay: 1.0s; }

/* Linked-app row */
.gdc-linked-app-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 40px;
    text-decoration: none !important;
    transition: background 0.25s;
    border-radius: 28px;
}
.gdc-linked-app-row:hover {
    background: rgba(137,194,224,0.06);
}
.gdc-linked-app-label {
    font-weight: 900;
    letter-spacing: 1px;
    font-size: 0.8rem;
    color: #fff;
}
.gdc-linked-app-id {
    font-family: monospace;
    color: var(--gph-blue);
    font-size: 0.8rem;
}

/* ── Admin group row ─────────────────────────────────────────────────── */
.gdc-admin-group-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 24px;
}
.gdc-admin-group-avatar {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    object-fit: cover;
    flex-shrink: 0;
    border: 1px solid rgba(182,8,201,0.4);
}
.gdc-admin-group-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
    flex: 1;
    min-width: 0;
}
.gdc-admin-group-name {
    color: #fff !important;
    font-weight: 800;
    font-size: 0.85rem;
    text-decoration: none !important;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: color 0.2s;
}
.gdc-admin-group-name:hover { color: var(--gph-magenta) !important; }
.gdc-admin-group-role {
    font-family: monospace;
    font-size: 0.6rem;
    color: var(--gph-magenta);
    text-transform: uppercase;
    letter-spacing: 1px;
}
.gdc-view-site-btn {
    flex-shrink: 0;
    border-color: rgba(137,194,224,0.35) !important;
    color: var(--gph-blue) !important;
}
.gdc-view-site-btn:hover {
    border-color: var(--gph-blue) !important;
    background: rgba(137,194,224,0.1) !important;
}

/* Balance grid */
.gdc-balance-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}
.gdc-node-content {
    padding: 30px 20px;
    text-align: center;
}
.gdc-node-label {
    font-family: monospace;
    font-size: 0.65rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 10px;
}
.gdc-node-value {
    font-size: 1.6rem;
    font-weight: 950;
    line-height: 1;
}

/* ══ 3. NAV BAR — fades in after the cards ════════════════════════════════
   ═══════════════════════════════════════════════════════════════════════ */
.gdc-profile-nav {
    margin-top: 60px;
    border-top: 1px solid var(--gph-border);
    background: rgba(0,0,0,0.3);
    opacity: 0;
    animation: gdcNavReveal 0.6s ease forwards 1.2s;
}
@keyframes gdcNavReveal {
    to { opacity: 1; }
}
.gdc-profile-nav-inner {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    gap: 40px;
    padding: 0 20px;
    overflow-x: auto;
    scrollbar-width: none;
}
.gdc-profile-nav-inner::-webkit-scrollbar { display: none; }
.gdc-nav-item {
    display: block;
    padding: 25px 0;
    color: #64748b;
    text-decoration: none !important;
    font-weight: 900;
    font-size: 0.75rem;
    letter-spacing: 2px;
    text-transform: uppercase;
    white-space: nowrap;
    border-bottom: 2px solid transparent;
    transition: color 0.25s, border-color 0.25s;
}
.gdc-nav-item:hover {
    color: rgba(255,255,255,0.7);
}
.gdc-nav-item--active {
    color: var(--gph-magenta);
    border-bottom-color: var(--gph-magenta);
}
/* Count badge inside nav items (e.g. "Groups 5") */
.gdc-nav-item .count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 7px;
    padding: 1px 7px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 20px;
    font-size: 0.6rem;
    font-weight: 700;
    color: rgba(255,255,255,0.4);
    letter-spacing: 0.5px;
    vertical-align: middle;
    line-height: 1.6;
}
.gdc-nav-item--active .count {
    background: rgba(182,8,201,0.15);
    border-color: rgba(182,8,201,0.35);
    color: var(--gph-magenta);
}

/* ══════════════════════════════════════════════════════════════════════
   YOUZIFY CONTENT SECTIONS — dark terminal aesthetic
   Overrides Youzify CSS custom properties so the cascade handles most
   elements automatically, then targets structural components directly.
   ══════════════════════════════════════════════════════════════════════ */

/* ── Page-level background ───────────────────────────────────────────── */
body.youzify-profile-page,
.youzify.youzify-profile,
#youzify,
#youzify-bp {
    background: #080b11 !important;
}

/* ── Youzify CSS custom-property overrides ───────────────────────────── */
.youzify.youzify-profile {
    --yzfy-body-color:                 #080b11;
    --yzfy-primary-color:              #e2e8f0;
    --yzfy-secondary-color:            #94a3b8;
    --yzfy-text-color:                 #cbd5e1;
    --yzfy-subtext-color:              #64748b;
    --yzfy-heading-color:              #ffffff;
    --yzfy-card-bg-color:              rgba(11,14,20,0.8);
    --yzfy-card-secondary-bg-color:    rgba(255,255,255,0.04);
    --yzfy-primary-border-color:       rgba(255,255,255,0.08);
    --yzfy-icon-color:                 #89C2E0;
    --yzfy-icon-bg-color:              rgba(137,194,224,0.1);
    --yzfy-button-bg-color:            rgba(255,255,255,0.06);
    --yzfy-button-text-color:          #e2e8f0;
    --yzfy-tab-text-color:             #ffffff;
    --yzfy-tab-bg-color:               rgba(255,255,255,0.06);
    --yzfy-shadow-color:               rgba(0,0,0,0.5);
    --yzfy-option-input-bg-color:      #0b0e14;
    --yzfy-option-input-color:         #cbd5e1;
    --yzfy-notice-primary-bg-color:    rgba(255,255,255,0.04);
    --yzfy-notice-primary-text-color:  #cbd5e1;
    --yzfy-menu-link-color:            #94a3b8;
    --yzfy-menu-icons-color:           #89C2E0;
}

/* ── Main content + sidebar layout ──────────────────────────────────── */
.youzify.youzify-profile .youzify-page-main-content,
.youzify.youzify-profile .youzify-content {
    background: transparent;
    padding-top: 0;
}
.youzify.youzify-profile .youzify-main-column {
    background: transparent;
    width: 100% !important;
    max-width: 100% !important;
    flex: 0 0 100% !important;
}
.youzify.youzify-profile .youzify-sidebar-column {
    display: none !important;
}

/* ── Widget cards ────────────────────────────────────────────────────── */
.youzify.youzify-profile .youzify-widget {
    background: rgba(11,14,20,0.75) !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    border-radius: 16px !important;
    box-shadow: 0 4px 32px rgba(0,0,0,0.4) !important;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    overflow: hidden;
    margin-bottom: 20px;
}
.youzify.youzify-profile .youzify-widget-head {
    background: rgba(255,255,255,0.03) !important;
    border-bottom: 1px solid rgba(255,255,255,0.07) !important;
    padding: 14px 20px !important;
}
.youzify.youzify-profile .youzify-widget-title {
    color: #fff !important;
    font-family: monospace !important;
    font-size: 0.65rem !important;
    font-weight: 700 !important;
    letter-spacing: 2px !important;
    text-transform: uppercase !important;
    margin: 0 !important;
}
.youzify.youzify-profile .youzify-widget-content {
    background: transparent !important;
    color: #cbd5e1;
    padding: 16px 20px;
}

/* ── Activity stream ─────────────────────────────────────────────────── */
.youzify.youzify-profile #activity-stream .activity-list > li,
.youzify.youzify-profile .activity-list > li {
    background: rgba(11,14,20,0.7) !important;
    border: 1px solid rgba(255,255,255,0.07) !important;
    border-radius: 14px !important;
    box-shadow: none !important;
    margin-bottom: 14px;
}
.youzify.youzify-profile .activity-content,
.youzify.youzify-profile .activity-header {
    background: transparent !important;
}
.youzify.youzify-profile .activity-header p,
.youzify.youzify-profile .activity-header a {
    color: #94a3b8 !important;
}
.youzify.youzify-profile .activity-content .activity-inner p,
.youzify.youzify-profile .activity-content .activity-inner {
    color: #cbd5e1 !important;
}

/* Activity post box */
.youzify.youzify-profile .activity-update-form,
.youzify.youzify-profile #whats-new-form {
    background: rgba(11,14,20,0.75) !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    border-radius: 14px !important;
    padding: 16px !important;
}
.youzify.youzify-profile #whats-new {
    background: rgba(255,255,255,0.04) !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    color: #e2e8f0 !important;
    border-radius: 8px;
}
.youzify.youzify-profile #whats-new::placeholder { color: #64748b !important; }

/* ── Generic list items (friends, groups tab, etc.) ─────────────────── */
.youzify.youzify-profile .youzify-list-item,
.youzify.youzify-profile ul.item-list > li {
    background: rgba(255,255,255,0.02) !important;
    border: 1px solid rgba(255,255,255,0.07) !important;
    border-radius: 12px !important;
    color: #cbd5e1 !important;
    margin-bottom: 10px;
}
.youzify.youzify-profile ul.item-list > li:hover {
    background: rgba(255,255,255,0.04) !important;
    border-color: rgba(137,194,224,0.25) !important;
}
.youzify.youzify-profile .item-list .item-title a,
.youzify.youzify-profile .item-list .item-meta {
    color: #e2e8f0 !important;
}
.youzify.youzify-profile .item-list .item-meta { color: #64748b !important; }

/* ── Connections / Members grid ──────────────────────────────────────── */
.youzify.youzify-profile .member-listing .list-wrap,
.youzify.youzify-profile #members-list .list-wrap {
    background: transparent;
}

/* ── Groups tab ─────────────────────────────────────────────────────── */
.youzify.youzify-profile #groups-list .list-wrap,
.youzify.youzify-profile .group-listing .list-wrap {
    background: transparent;
}

/* ── Profile fields / xprofile ───────────────────────────────────────── */
.youzify.youzify-profile .bp-profile-section,
.youzify.youzify-profile #profile-edit-form .editfield {
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 10px;
}
.youzify.youzify-profile .bp-profile-section dt,
.youzify.youzify-profile .bp-profile-section label {
    color: #64748b !important;
    font-size: 0.65rem;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    font-family: monospace;
}
.youzify.youzify-profile .bp-profile-section dd,
.youzify.youzify-profile .bp-profile-section p {
    color: #e2e8f0 !important;
}

/* ── Tabs (sub-tabs inside sections) ─────────────────────────────────── */
.youzify.youzify-profile .item-body nav.bp-navs ul li a,
.youzify.youzify-profile .youzify-tabs-nav a {
    color: #64748b !important;
    border-bottom-color: transparent !important;
}
.youzify.youzify-profile .item-body nav.bp-navs ul li.current a,
.youzify.youzify-profile .youzify-tabs-nav a.selected {
    color: #b608c9 !important;
    border-bottom-color: #b608c9 !important;
}

/* ── Pagination ──────────────────────────────────────────────────────── */
.youzify.youzify-profile .pagination-links a,
.youzify.youzify-profile .pagination-links span {
    background: rgba(255,255,255,0.04) !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    color: #94a3b8 !important;
    border-radius: 8px;
}
.youzify.youzify-profile .pagination-links .current {
    background: rgba(182,8,201,0.15) !important;
    border-color: rgba(182,8,201,0.35) !important;
    color: #b608c9 !important;
}

/* ── Buttons (generic WP buttons in content) ─────────────────────────── */
.youzify.youzify-profile .generic-button a,
.youzify.youzify-profile .generic-button button {
    background: rgba(255,255,255,0.06) !important;
    border: 1px solid rgba(255,255,255,0.12) !important;
    color: #e2e8f0 !important;
    border-radius: 10px;
    transition: border-color 0.2s, background 0.2s;
}
.youzify.youzify-profile .generic-button a:hover,
.youzify.youzify-profile .generic-button button:hover {
    background: rgba(137,194,224,0.1) !important;
    border-color: rgba(137,194,224,0.4) !important;
}

/* ── No-content states ───────────────────────────────────────────────── */
.youzify.youzify-profile .bp-feedback.info,
.youzify.youzify-profile .youzify-no-data {
    background: rgba(255,255,255,0.02) !important;
    border: 1px dashed rgba(255,255,255,0.1) !important;
    border-radius: 12px;
    color: #64748b !important;
}
';
}

// ─── Global Youzify CSS (all pages) ──────────────────────────────────────────

function gdc_global_youzify_css() {
    return '
/* ════════════════════════════════════════════════════════════════════════
   GDC GLOBAL — dark terminal palette across all BuddyPress / Youzify pages
   Scope: .youzify root (directories, activity, group pages, profile pages)
   ════════════════════════════════════════════════════════════════════════ */

/* ── 0. Leo chat widget hard-exemption ───────────────────────────────────
   backdrop-filter on ancestor elements creates a new containing block for
   position:fixed children, which can trap and clip the chat widget.
   Explicitly protect <aipa-widget> so none of our rules ever touch it.   */
aipa-widget,
aipa-widget * {
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
}
aipa-widget {
    display: block !important;
    visibility: visible !important;
    position: fixed !important;
    z-index: 2147483645 !important;
    contain: none !important;
}

/* ── 1. Page background ──────────────────────────────────────────────── */
body.buddypress,
body.bp-user,
body.bp-directory,
body.buddypress-page,
#youzify,
#youzify-bp,
.youzify {
    background: #080b11 !important;
}
.youzify .youzify-content,
.youzify .youzify-page-main-content,
.youzify .youzify-main-column,
.youzify .youzify-sidebar-column,
.youzify .youzify-group-content,
.youzify .youzify-inner-content,
.youzify .youzify-column-content {
    background: transparent !important;
}

/* ── 2. CSS custom-property palette (cascades to most child elements) ── */
.youzify {
    --yzfy-body-color:                 #080b11;
    --yzfy-primary-color:              #e2e8f0;
    --yzfy-secondary-color:            #94a3b8;
    --yzfy-text-color:                 #cbd5e1;
    --yzfy-subtext-color:              #64748b;
    --yzfy-heading-color:              #ffffff;
    --yzfy-card-bg-color:              rgba(11,14,20,0.82);
    --yzfy-card-secondary-bg-color:    rgba(255,255,255,0.04);
    --yzfy-primary-border-color:       rgba(255,255,255,0.08);
    --yzfy-icon-color:                 #89C2E0;
    --yzfy-icon-bg-color:              rgba(137,194,224,0.1);
    --yzfy-button-bg-color:            rgba(255,255,255,0.06);
    --yzfy-button-text-color:          #e2e8f0;
    --yzfy-tab-text-color:             #ffffff;
    --yzfy-tab-bg-color:               rgba(255,255,255,0.06);
    --yzfy-shadow-color:               rgba(0,0,0,0.5);
    --yzfy-option-input-bg-color:      #0b0e14;
    --yzfy-option-input-color:         #cbd5e1;
    --yzfy-notice-primary-bg-color:    rgba(255,255,255,0.04);
    --yzfy-notice-primary-text-color:  #cbd5e1;
    --yzfy-menu-link-color:            #94a3b8;
    --yzfy-menu-icons-color:           #89C2E0;
    font-family: "Inter", sans-serif;
    color: #cbd5e1;
}

/* ══ NAVIGATION BARS (directory filter, tab rows) ════════════════════════ */

/* Primary tab bar used by directories + activity + group nav */
/* NOTE: no backdrop-filter here — these sit high in the DOM tree and
   backdrop-filter creates a new containing block that traps position:fixed
   children (e.g. the Leo AI chat widget), clipping them off-screen. */
.youzify .youzify-directory-filter,
.youzify .item-list-tabs:not(.activity-type-tabs-subnav) {
    background: rgba(8,11,17,0.9) !important;
    border-color: rgba(255,255,255,0.08) !important;
}
/* Sub-nav / secondary filter row */
.youzify .item-list-tabs.activity-type-tabs-subnav,
.youzify #subnav {
    background: rgba(11,14,20,0.85) !important;
    border-color: rgba(255,255,255,0.06) !important;
}
/* Tab link text */
.youzify div.item-list-tabs li a,
.youzify div.item-list-tabs li a span {
    color: #64748b !important;
}
.youzify div.item-list-tabs li.selected > a,
.youzify div.item-list-tabs li.current > a {
    color: #b608c9 !important;
    border-bottom-color: #b608c9 !important;
}
.youzify div.item-list-tabs li a:hover {
    color: rgba(255,255,255,0.75) !important;
}
/* Count badge in tabs */
.youzify div.item-list-tabs li a span {
    background: rgba(255,255,255,0.07) !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    border-radius: 20px;
    padding: 1px 7px;
    font-size: 0.65rem;
}
.youzify div.item-list-tabs li.selected a span,
.youzify div.item-list-tabs li.current a span {
    background: rgba(182,8,201,0.18) !important;
    border-color: rgba(182,8,201,0.4) !important;
    color: #b608c9 !important;
}
/* Search field inside filter bars */
.youzify .dir-search input[type="search"],
.youzify .dir-search input[type="text"],
.youzify .youzify-activity-search input {
    background: rgba(255,255,255,0.04) !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    color: #e2e8f0 !important;
    border-radius: 8px !important;
}
.youzify .dir-search input::placeholder,
.youzify .youzify-activity-search input::placeholder { color: #64748b !important; }

/* Sort / filter dropdowns */
.youzify .youzify-bar-select,
.youzify div.item-list-tabs .nice-select {
    background: rgba(255,255,255,0.05) !important;
    border-color: rgba(255,255,255,0.1) !important;
    color: #94a3b8 !important;
    border-radius: 8px !important;
}

/* ══ DIRECTORY CARDS — Groups & Members ══════════════════════════════════ */

/* Group & member list items */
.youzify #youzify-groups-list > li,
.youzify #youzify-members-list > li,
.youzify .item-list > li {
    background: rgba(11,14,20,0.78) !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    border-radius: 14px !important;
    box-shadow: 0 4px 24px rgba(0,0,0,0.35) !important;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    transition: border-color 0.25s, box-shadow 0.25s;
    margin-bottom: 12px;
    color: #cbd5e1;
}
.youzify #youzify-groups-list > li:hover,
.youzify #youzify-members-list > li:hover,
.youzify .item-list > li:hover {
    border-color: rgba(137,194,224,0.3) !important;
    box-shadow: 0 4px 32px rgba(137,194,224,0.08) !important;
}

/* Card inner data wrappers */
.youzify .youzify-group-data,
.youzify .youzify-user-data {
    background: transparent !important;
}

/* Item title links */
.youzify .item-list .item-title a,
.youzify .item-list .item-title {
    color: #ffffff !important;
    font-weight: 700;
}
.youzify .item-list .item-title a:hover {
    color: #89C2E0 !important;
}

/* Item meta / description */
.youzify .item-list .item-meta,
.youzify .item-list .item-desc,
.youzify .item-list .desc,
.youzify .item-list .activity {
    color: #64748b !important;
    font-size: 0.78rem;
}

/* Group status badge */
.youzify .item-list .group-status {
    color: #b608c9 !important;
    font-family: monospace;
    font-size: 0.6rem;
    letter-spacing: 1px;
    text-transform: uppercase;
}

/* Action buttons on cards */
.youzify .item-list .action a,
.youzify .item-list .generic-button a,
.youzify .youzify-user-actions a,
.youzify .youzify-user-actions button {
    background: rgba(255,255,255,0.06) !important;
    border: 1px solid rgba(255,255,255,0.12) !important;
    color: #e2e8f0 !important;
    border-radius: 10px !important;
    font-size: 0.7rem !important;
    font-weight: 700 !important;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: border-color 0.2s, background 0.2s;
}
.youzify .item-list .action a:hover,
.youzify .item-list .generic-button a:hover,
.youzify .youzify-user-actions a:hover {
    background: rgba(137,194,224,0.1) !important;
    border-color: rgba(137,194,224,0.4) !important;
    color: #89C2E0 !important;
}

/* Group cover in card */
.youzify .youzify-group-cover-image,
.youzify .youzify-user-cover {
    border-radius: 10px 10px 0 0;
    overflow: hidden;
}

/* ══ SIDEBAR WIDGETS ═════════════════════════════════════════════════════ */

.youzify .youzify-sidebar .widget,
.youzify .youzify-sidebar .widget-content {
    background: rgba(11,14,20,0.78) !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    border-radius: 14px !important;
    box-shadow: 0 4px 24px rgba(0,0,0,0.35) !important;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
}
.youzify .youzify-sidebar .widget-content .widget-title {
    color: #ffffff !important;
    font-family: monospace !important;
    font-size: 0.65rem !important;
    letter-spacing: 2px !important;
    text-transform: uppercase !important;
    border-bottom: 1px solid rgba(255,255,255,0.08) !important;
    padding-bottom: 10px !important;
}
.youzify .youzify-sidebar .widget-content .widget-title::before,
.youzify .youzify-sidebar .widget-content .widget-title::after {
    color: #b608c9 !important;
}
.youzify .youzify-sidebar .item-list li {
    border-bottom: 1px solid rgba(255,255,255,0.05) !important;
    color: #94a3b8 !important;
}
.youzify .youzify-sidebar .item-list li a {
    color: #e2e8f0 !important;
}
.youzify .youzify-sidebar .item-list li a:hover {
    color: #89C2E0 !important;
}

/* ══ ACTIVITY FEED PAGE ══════════════════════════════════════════════════ */

/* Activity stream wrapper */
.youzify.youzify-global-wall .activity,
.youzify .activity {
    background: transparent !important;
}

/* Activity post box */
.youzify .activity-update-form,
.youzify #whats-new-form,
.youzify .youzify-new-post-form {
    background: rgba(11,14,20,0.8) !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    border-radius: 14px !important;
    padding: 16px !important;
}
.youzify #whats-new,
.youzify .youzify-post-field {
    background: rgba(255,255,255,0.04) !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    color: #e2e8f0 !important;
    border-radius: 8px;
}
.youzify #whats-new::placeholder,
.youzify .youzify-post-field::placeholder { color: #64748b !important; }

/* Activity list items */
.youzify #activity-stream .activity-list > li,
.youzify .activity-list > li {
    background: rgba(11,14,20,0.78) !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    border-radius: 14px !important;
    box-shadow: 0 4px 24px rgba(0,0,0,0.3) !important;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    margin-bottom: 14px;
}
.youzify #activity-stream .activity-list > li:hover,
.youzify .activity-list > li:hover {
    border-color: rgba(137,194,224,0.2) !important;
}
.youzify .activity-content,
.youzify .activity-header {
    background: transparent !important;
}
.youzify .activity-header p,
.youzify .activity-header a {
    color: #94a3b8 !important;
}
.youzify .activity-header a:hover { color: #89C2E0 !important; }
.youzify .activity-content .activity-inner,
.youzify .activity-content .activity-inner p {
    color: #cbd5e1 !important;
}
/* Activity meta (like / comment links) */
.youzify .activity-meta a {
    color: #64748b !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    border-radius: 8px;
    padding: 4px 10px;
    font-size: 0.7rem;
    font-weight: 700;
    transition: color 0.2s, border-color 0.2s;
}
.youzify .activity-meta a:hover,
.youzify .activity-meta a.selected {
    color: #b608c9 !important;
    border-color: rgba(182,8,201,0.4) !important;
}

/* Comment thread inside activity */
.youzify .ac-form,
.youzify .activity-comments {
    background: rgba(255,255,255,0.02) !important;
    border-top: 1px solid rgba(255,255,255,0.06) !important;
}
.youzify .ac-form textarea,
.youzify .ac-form input {
    background: rgba(11,14,20,0.8) !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    color: #e2e8f0 !important;
    border-radius: 8px;
}
.youzify .activity-comments .acomment-meta { color: #64748b !important; }
.youzify .activity-comments .acomment-content { color: #cbd5e1 !important; }

/* ══ GROUP PAGES ═════════════════════════════════════════════════════════ */

/* Group header banner */
.youzify.youzify-group #youzify-group-header,
.youzify .youzify-group-header {
    background: rgba(8,11,17,0.9) !important;
    border-bottom: 1px solid rgba(255,255,255,0.08) !important;
    /* No backdrop-filter — high in the group page DOM tree */
}

/* Group header text */
.youzify #youzify-group-header .item-title,
.youzify #youzify-group-header .item-title a {
    color: #ffffff !important;
    font-weight: 800;
}
.youzify #youzify-group-header .item-meta { color: #64748b !important; }
.youzify #youzify-group-header .group-status {
    color: #b608c9 !important;
    font-family: monospace;
    font-size: 0.65rem;
    letter-spacing: 1px;
    text-transform: uppercase;
}

/* Group sub-navigation */
.youzify.youzify-group #youzify-profile-navmenu,
.youzify.youzify-group #subnav {
    background: rgba(8,11,17,0.85) !important;
    border-color: rgba(255,255,255,0.08) !important;
}

/* Group content panels */
.youzify.youzify-group .youzify-widget {
    background: rgba(11,14,20,0.78) !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    border-radius: 14px !important;
    box-shadow: 0 4px 24px rgba(0,0,0,0.35) !important;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    margin-bottom: 16px;
}
.youzify.youzify-group .youzify-widget-head {
    background: rgba(255,255,255,0.03) !important;
    border-bottom: 1px solid rgba(255,255,255,0.07) !important;
    padding: 14px 20px !important;
}
.youzify.youzify-group .youzify-widget-title {
    color: #fff !important;
    font-family: monospace !important;
    font-size: 0.65rem !important;
    font-weight: 700 !important;
    letter-spacing: 2px !important;
    text-transform: uppercase !important;
    margin: 0 !important;
}

/* Group admin settings panels */
.youzify .youzify-group-settings-tab {
    background: rgba(11,14,20,0.78) !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    border-radius: 14px !important;
    padding: 24px !important;
}
.youzify .youzify-group-field-item label {
    color: #64748b !important;
    font-family: monospace;
    font-size: 0.65rem;
    letter-spacing: 1.5px;
    text-transform: uppercase;
}
.youzify .youzify-group-field-item input,
.youzify .youzify-group-field-item textarea,
.youzify .youzify-group-field-item select {
    background: rgba(255,255,255,0.04) !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    color: #e2e8f0 !important;
    border-radius: 8px;
}
.youzify .youzify-group-submit-form .button,
.youzify .youzify-group-submit-form button {
    background: rgba(182,8,201,0.15) !important;
    border: 1px solid rgba(182,8,201,0.4) !important;
    color: #b608c9 !important;
    border-radius: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Group member cards inside group pages */
.youzify.youzify-group .members-list > li {
    background: rgba(255,255,255,0.02) !important;
    border: 1px solid rgba(255,255,255,0.07) !important;
    border-radius: 10px !important;
    margin-bottom: 8px;
    color: #cbd5e1;
}

/* ══ SHARED — PAGINATION, FEEDBACK, FORMS ════════════════════════════════ */

.youzify .pagination-links a,
.youzify .pagination-links span {
    background: rgba(255,255,255,0.04) !important;
    border: 1px solid rgba(255,255,255,0.08) !important;
    color: #94a3b8 !important;
    border-radius: 8px;
}
.youzify .pagination-links .current {
    background: rgba(182,8,201,0.15) !important;
    border-color: rgba(182,8,201,0.35) !important;
    color: #b608c9 !important;
}

.youzify .bp-feedback.info,
.youzify .youzify-no-data,
.youzify #message.info {
    background: rgba(255,255,255,0.02) !important;
    border: 1px dashed rgba(255,255,255,0.1) !important;
    border-radius: 12px;
    color: #64748b !important;
}

/* Generic form elements across all BP pages */
.youzify input[type="text"],
.youzify input[type="email"],
.youzify input[type="password"],
.youzify textarea,
.youzify select {
    background: rgba(255,255,255,0.04) !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    color: #e2e8f0 !important;
}
.youzify input::placeholder,
.youzify textarea::placeholder { color: #64748b !important; }

/* Mobile nav bar */
.youzify .youzify-mobile-nav {
    background: rgba(8,11,17,0.95) !important;
    border-color: rgba(255,255,255,0.08) !important;
}
.youzify .youzify-mobile-nav-container a {
    color: #94a3b8 !important;
}
';
}
