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

    // The DGEN top-up popup embeds the live reward-programs wallet card. We render
    // the wallet hidden on this page (see gdc_render_profile_header) so the plugin
    // binds its action buttons + parses card data at init; then the popup relocates
    // that bound node. So we need the plugin's OWN css + js + GEND_WALLET config.
    if ( ! is_user_logged_in() ) return;
    $rp_dir = WP_CONTENT_DIR . '/plugins/reward-programs/assets/';
    $rp_url = content_url( '/plugins/reward-programs/assets/' );
    if ( file_exists( $rp_dir . 'frontend-wallet.css' ) ) {
        wp_enqueue_style( 'gend-wallet-frontend', $rp_url . 'frontend-wallet.css', [], filemtime( $rp_dir . 'frontend-wallet.css' ) );
    }
    if ( file_exists( $rp_dir . 'frontend-wallet.js' ) ) {
        wp_enqueue_script( 'gend-wallet-frontend', $rp_url . 'frontend-wallet.js', [ 'jquery' ], filemtime( $rp_dir . 'frontend-wallet.js' ), true );
        wp_localize_script( 'gend-wallet-frontend', 'GEND_WALLET', [
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'gend_wallet_nonce' ),
            'i18n'  => [ 'processing' => __( 'Processing…', 'reward-programs' ) ],
        ] );
    }
}

// Source wallet for the DGEN top-up popup. Rendered ONCE, late, at body level
// and kept fully off-screen — the reward-programs JS binds it + parses card data
// at init, then the popup relocates this bound node in and out of itself. Done
// via wp_footer (not the header markup) so it cannot duplicate when Youzify fires
// its before-header hook more than once, and cannot disturb the header/menu.
add_action( 'wp_footer', 'gdc_render_wallet_source', 5 );
function gdc_render_wallet_source() {
    static $done = false;
    if ( $done ) return;
    if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() || ! bp_is_my_profile() ) return;
    if ( ! shortcode_exists( 'gend_wallet' ) ) return;
    $done = true;
    echo '<div id="gdc-wallet-home" aria-hidden="true" tabindex="-1" style="position:fixed;left:-99999px;top:0;width:1px;height:1px;overflow:hidden;opacity:0;pointer-events:none;">'
        . do_shortcode( '[gend_wallet]' )
        . '</div>';
}

// ─── Wallet Top-Up purchase handler ──────────────────────────────────────────
// The three header Top-Up popups navigate (GET) to /?gdc_topup=<kind>… . We run
// on template_redirect — a FRONTEND request where WooCommerce's cart IS loaded
// (admin-post.php is admin context and never loads the cart, which is why a POST
// there lands on an empty checkout). We add the right product, then redirect to
// the normal WooCommerce checkout so the buyer pays with any configured gateway.
//   tasks → sales-team "Task Credit Top-up" product, qty = credits (grants `tasks`)
//   ai    → leo aipa-credits product (grants AI Builder Tokens)
//   dgen  → contracts-and-payments DGEN product (credits `transact` 1:1)
add_action( 'template_redirect', 'gdc_handle_wallet_topup_purchase' );
function gdc_handle_wallet_topup_purchase() {
    if ( empty( $_GET['gdc_topup'] ) ) return;
    $kind = sanitize_key( wp_unslash( $_GET['gdc_topup'] ) );
    if ( ! in_array( $kind, [ 'tasks', 'ai', 'dgen' ], true ) ) return;

    // Login is the real gate. The nonce is best-effort CSRF defense: this only
    // (re)populates the LOGGED-IN user's OWN cart and routes to the WooCommerce
    // checkout (payment is independently secured), so a STALE nonce — e.g. the
    // profile page sat open across a login/session rotation — must not hard-block
    // a legitimate buyer with "Security check failed". SameSite=Lax auth cookies
    // already block cross-site cookie'd requests, so the CSRF surface is minimal.
    if ( ! is_user_logged_in() ) { auth_redirect(); exit; }
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
        wp_safe_redirect( home_url( '/' ) ); exit;
    }

    $amount = isset( $_GET['amount'] ) ? round( (float) wp_unslash( $_GET['amount'] ), 2 ) : 0.0;
    $qty    = isset( $_GET['qty'] ) ? absint( wp_unslash( $_GET['qty'] ) ) : 0;
    $back   = wp_get_referer() ?: home_url( '/' );

    WC()->cart->empty_cart();

    if ( 'tasks' === $kind ) {
        $extra_pid = (int) apply_filters( 'aas_task_checkout_extra_credit_product_id', 0 );
        $n = max( 1, $qty );
        if ( ! $extra_pid ) { wp_safe_redirect( $back ); exit; }
        $rate = 50.0;
        if ( class_exists( 'ST_Contract_Settings' ) ) {
            $cs   = ST_Contract_Settings::get();
            $rate = (float) ( $cs['credit_value'] ?? 50.0 );
        }
        // qty = number of task credits; price each at the per-credit rate.
        WC()->cart->add_to_cart( $extra_pid, $n, 0, [], [ 'gdc_topup_unit_price' => $rate, 'gdc_topup_kind' => 'tasks' ] );

    } elseif ( 'ai' === $kind ) {
        if ( ! class_exists( 'AIPA_Commerce' ) ) { wp_safe_redirect( $back ); exit; }
        $amt = max( 1, $amount );
        $pid = AIPA_Commerce::ensure_product();
        if ( ! $pid ) { wp_safe_redirect( $back ); exit; }
        // leo's own before_calculate_totals prices this from aipa_credit_amount.
        WC()->cart->add_to_cart( $pid, 1, 0, [], [ 'aipa_credit_amount' => $amt ] );

    } elseif ( 'dgen' === $kind ) {
        if ( ! class_exists( 'Gend_CP_DGEN_TopUp' ) ) { wp_safe_redirect( $back ); exit; }
        $amt = max( 1, $amount );
        $pid = Gend_CP_DGEN_TopUp::ensure_product();
        if ( ! $pid ) { wp_safe_redirect( $back ); exit; }
        // Gend_CP_DGEN_TopUp prices + persists + credits from this cart key.
        WC()->cart->add_to_cart( $pid, 1, 0, [], [ Gend_CP_DGEN_TopUp::CART_KEY => $amt ] );
    }

    // If the iframe pointed straight at the checkout page, the cart is now
    // populated — let it render in THIS request instead of a 302 to another full
    // WP boot (halves the embed's load time). Otherwise redirect to checkout.
    if ( function_exists( 'is_checkout' ) && is_checkout() ) {
        return;
    }
    $checkout_url = wc_get_checkout_url();
    if ( ! empty( $_GET['gend_embed'] ) ) {
        $checkout_url = add_query_arg( 'gend_embed', '1', $checkout_url );
    }
    wp_safe_redirect( $checkout_url );
    exit;
}

// When the checkout is loaded inside the Top-Up popup iframe (?gend_embed=1),
// strip the theme header/footer/admin-bar/chat-widget so only the checkout
// payment form shows. Scoped strictly to that query flag — never affects normal
// page loads.
add_filter( 'show_admin_bar', 'gdc_topup_embed_hide_admin_bar' );
function gdc_topup_embed_hide_admin_bar( $show ) {
    return empty( $_GET['gend_embed'] ) ? $show : false;
}

// Inside the embed iframe, render the CLASSIC checkout (server-rendered payment
// methods) instead of the React checkout BLOCK — the block hydrates client-side
// and reliably fails to surface payment gateways inside an iframe. Classic
// checkout prints every enabled gateway in the HTML and works in the frame.
add_filter( 'render_block', 'gdc_topup_embed_classic_checkout', 10, 2 );
function gdc_topup_embed_classic_checkout( $block_content, $block ) {
    if ( empty( $_GET['gend_embed'] ) ) return $block_content;
    if ( ! empty( $block['blockName'] ) && 'woocommerce/checkout' === $block['blockName'] ) {
        return do_shortcode( '[woocommerce_checkout]' );
    }
    return $block_content;
}

// Inside the embed iframe, drop the heavy site-wide frontend assets the checkout
// doesn't need (youzify ~175 refs, the LEO AI widget bundle, the gend-society
// frontend bar / template modal, BuddyPress). Keeps everything WooCommerce /
// payment-gateway / jQuery / WP-core so the classic checkout still works. This
// is the bulk of the embed's load weight.
add_action( 'wp_enqueue_scripts', 'gdc_topup_embed_trim_assets', 9999 );
// Also run right before footer scripts/styles print — the Woo blocks bundle is
// enqueued during block render (the_content), AFTER wp_enqueue_scripts, so the
// early pass alone misses it. wp_print_footer_scripts (10) prints them; we run
// at 1 to dequeue first.
add_action( 'wp_print_footer_scripts', 'gdc_topup_embed_trim_assets', 1 );
function gdc_topup_embed_trim_assets() {
    if ( empty( $_GET['gend_embed'] ) ) return;
    $kill = [ 'youzify', 'aipa-widget', 'leo-flow', 'leo-widget', 'gs-frontend-bar', 'gs-template-modal', 'gs-site-editor', 'gs-animation', 'buddypress', 'bp-' ];
    $keep = [ 'woocommerce', 'wc-', 'wc_', 'ppcp', 'paypal', 'jquery', 'wp-', 'select', 'sourcebuster', 'stripe', 'checkout', 'dashicons' ];
    $hit  = function ( $h ) use ( $kill, $keep ) {
        $h = (string) $h;
        // The Woo cart/checkout BLOCKS bundle is dead weight here — we render the
        // CLASSIC checkout. Drop it BEFORE the keep-list (which keeps wc-*).
        if ( strpos( $h, 'wc-blocks' ) === 0 ) return true;
        foreach ( $keep as $k ) { if ( strpos( $h, $k ) !== false ) return false; }
        foreach ( $kill as $p ) { if ( strpos( $h, $p ) === 0 ) return true; }
        return false;
    };
    global $wp_scripts, $wp_styles;
    if ( $wp_scripts && ! empty( $wp_scripts->registered ) ) {
        foreach ( array_keys( $wp_scripts->registered ) as $h ) { if ( $hit( $h ) ) wp_dequeue_script( $h ); }
    }
    if ( $wp_styles && ! empty( $wp_styles->registered ) ) {
        foreach ( array_keys( $wp_styles->registered ) as $h ) { if ( $hit( $h ) ) wp_dequeue_style( $h ); }
    }
}

// De-duplicate the checkout payment methods on the gend.me HUB. The customer-
// container "bridge" gateways (which exist to redirect a container's checkout TO
// the hub) are also registered here and duplicate every hub-native method
// (e.g. "Pay with PayPal"/gend_paypal vs ppcp, "Pay with DGEN balance"/gend_dgen
// vs gend_dgen_hub). On the hub the bridge variants are redundant (they'd point
// the hub at itself), so drop them — leaving one clean option per method:
// ppcp (PayPal/card), gend_dgen_hub (DGEN), gend_btcpay_hub (BTC/Lightning),
// gend_evm_hub (USDC/ETH), mycred (Store Credits). Hub-only via is_main_node;
// reversible (pure filter — changes no gateway settings).
add_filter( 'woocommerce_available_payment_gateways', 'gdc_dedupe_hub_payment_gateways', 100 );
function gdc_dedupe_hub_payment_gateways( $gateways ) {
    if ( ! is_array( $gateways ) ) return $gateways;
    $is_hub = ! class_exists( 'Gend_CP_OAuth_Resource' )
        || ! method_exists( 'Gend_CP_OAuth_Resource', 'is_main_node' )
        || Gend_CP_OAuth_Resource::is_main_node();
    if ( ! $is_hub ) return $gateways;
    // Container "bridge" gateways (redundant on the hub) + gend_dgen_hub: DGEN is
    // applied via the C&P wallet panel ("Apply your DGEN and/or store credit") that
    // renders above the gateways, so a separate "Pay with DGEN" gateway radio just
    // duplicates it. Store credit is likewise handled by that panel.
    foreach ( [ 'gend_token', 'gend_paypal', 'gend_btcpay', 'gend_usdc', 'gend_dgen', 'gend_dgen_hub' ] as $remove_id ) {
        unset( $gateways[ $remove_id ] );
    }
    return $gateways;
}

// Suppress the LEO/<aipa-widget> chat bubble AT THE SOURCE inside the embed —
// unhook its enqueue + footer render before they fire (on `wp`, which runs before
// wp_enqueue_scripts and wp_footer). CSS/DOM removal alone loses to the widget's
// late re-mount + inline styles, so kill it before it ever loads. Covers both
// LEO's global functions and the gend-society fallback widget.
add_action( 'wp', 'gdc_topup_embed_kill_chat_widget' );
function gdc_topup_embed_kill_chat_widget() {
    if ( empty( $_GET['gend_embed'] ) ) return;
    remove_action( 'wp_footer', 'aipa_widget_render_footer', 99999 );
    remove_action( 'wp_enqueue_scripts', 'aipa_widget_register_scripts', 5 );
    remove_action( 'wp_enqueue_scripts', 'aipa_widget_load_assets', 10 );
    if ( class_exists( 'GS_AI_Widget' ) ) {
        remove_action( 'wp_footer', [ 'GS_AI_Widget', 'render_footer' ], 99999 );
        remove_action( 'wp_enqueue_scripts', [ 'GS_AI_Widget', 'enqueue' ], 5 );
    }
}

add_action( 'wp_enqueue_scripts', 'gdc_topup_embed_checkout_css', 100 );
function gdc_topup_embed_checkout_css() {
    if ( empty( $_GET['gend_embed'] ) ) return;
    // gend_embed=1 already suppresses the gend-society frontend bar + mini-cart
    // (gs_is_embed_request). This strips the rest of the theme chrome + the AI
    // chat widget so the iframe shows ONLY the checkout. Float-bar selectors are
    // kept as belt-and-suspenders.
    $css = '
        html { margin-top: 0 !important; }
        body { background: #0a0f1a !important; padding: 16px !important; }
        #wpadminbar, header, footer, #masthead, #colophon, .site-header, .site-footer,
        .youzify-mobile-nav, #gdc-profile-nav,
        .gs-float-menu, #gs-float-menu, #gs-front-sidebar, .gs-front-sidebar, .gs-float-dock,
        #gs-mini-cart-overlay, #gs-mini-cart-trigger,
        aipa-widget, leo-widget, #aipa-widget, .aipa-widget, [id^="aipa-"], [class*="leo-launcher"] {
            display: none !important;
            visibility: hidden !important;
        }
        .woocommerce, .wc-block-checkout, .wp-block-woocommerce-checkout { background: transparent !important; }

        /* ══ "Pay with your balance" → self-assembling futuristic dashboard ══ */
        .gend-wallet-classic {
            position: relative;
            display: flex; flex-wrap: wrap; gap: 14px 22px; align-items: flex-start;
            padding: 26px 26px 22px; margin-bottom: 22px;
            border-radius: 20px;
            background: linear-gradient(160deg, rgba(20,26,40,.92), rgba(10,14,22,.94));
            border: 1px solid rgba(137,194,224,.16);
            box-shadow: 0 22px 60px -26px rgba(0,0,0,.75), inset 0 1px 0 rgba(255,255,255,.05);
            overflow: hidden;
            opacity: 0; transform: translateY(20px) scale(.99);
            animation: gwcCardIn .7s cubic-bezier(.16,1,.3,1) forwards;
        }
        @keyframes gwcCardIn { to { opacity: 1; transform: none; } }
        /* scanning top light */
        .gend-wallet-classic::before {
            content: ""; position: absolute; left: 0; right: 0; top: 0; height: 2px;
            background: linear-gradient(90deg, transparent, #89C2E0, #b608c9, #00ff88, transparent);
            background-size: 200% 100%; animation: gwcScan 6s linear infinite; opacity: .75;
        }
        @keyframes gwcScan { from { background-position: 200% 0; } to { background-position: -200% 0; } }

        /* staggered "builds itself" entrance for each child */
        .gend-wallet-classic > * { opacity: 0; animation: gwcBuildIn .58s cubic-bezier(.22,1,.36,1) forwards; }
        .gend-wallet-classic > *:nth-child(1) { animation-delay: .18s; }
        .gend-wallet-classic > *:nth-child(2) { animation-delay: .30s; }
        .gend-wallet-classic > *:nth-child(3) { animation-delay: .44s; }
        .gend-wallet-classic > *:nth-child(4) { animation-delay: .58s; }
        .gend-wallet-classic > *:nth-child(5) { animation-delay: .72s; }
        .gend-wallet-classic > *:nth-child(6) { animation-delay: .86s; }
        @keyframes gwcBuildIn { from { opacity: 0; transform: translateY(16px) scale(.97); } to { opacity: 1; transform: none; } }

        /* header + badge */
        .gend-wallet-classic .gwc-head { flex: 0 0 100%; display: flex; align-items: center; gap: 12px; font-size: 1rem; font-weight: 900; color: #fff; letter-spacing: .4px; margin: 0; padding: 0; }
        .gend-wallet-classic .gwc-badge { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; color: #0a0f1a; background: linear-gradient(135deg,#a78bfa,#7857ff); animation: gwcBadge 2.6s ease-in-out infinite; flex: 0 0 auto; }
        @keyframes gwcBadge { 0%,100% { box-shadow: 0 0 12px rgba(120,87,255,.45); } 50% { box-shadow: 0 0 26px rgba(120,87,255,.85); } }
        .gend-wallet-classic .gwc-sub { flex: 0 0 100%; width: 100%; margin: 0; padding: 0; color: #94a3b8; font-size: .85rem; line-height: 1.5; }

        /* balance rows → glowing metric tiles (DGEN green, store credit pink) */
        .gend-wallet-classic .gwc-row {
            flex: 1 1 calc(50% - 11px); min-width: 240px; box-sizing: border-box; position: relative; overflow: hidden;
            padding: 18px 20px 20px 26px !important; margin: 0 !important; border-radius: 16px;
            background: rgba(255,255,255,.025); border: 1px solid rgba(255,255,255,.08);
            transition: border-color .3s, box-shadow .3s, transform .3s; --gwc-accent: #89C2E0;
        }
        .gend-wallet-classic .gwc-row:has(input[data-type="dgen"]) { --gwc-accent: #00ff88; }
        .gend-wallet-classic .gwc-row:has(input[data-type="credit"]) { --gwc-accent: #f0598a; }
        /* accent edge bar + a soft radial glow in the tile corner */
        .gend-wallet-classic .gwc-row::before { content: ""; position: absolute; left: 0; top: 12px; bottom: 12px; width: 4px; border-radius: 0 4px 4px 0; background: var(--gwc-accent); box-shadow: 0 0 12px var(--gwc-accent); }
        .gend-wallet-classic .gwc-row::after { content: ""; position: absolute; right: -40px; top: -40px; width: 130px; height: 130px; border-radius: 50%; background: radial-gradient(circle, color-mix(in srgb, var(--gwc-accent) 22%, transparent), transparent 70%); pointer-events: none; opacity: .8; }
        .gend-wallet-classic .gwc-row:hover { transform: translateY(-3px); border-color: color-mix(in srgb, var(--gwc-accent) 55%, transparent); box-shadow: 0 0 28px -8px var(--gwc-accent); }
        .gend-wallet-classic .gwc-row-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 14px; padding: 0; position: relative; z-index: 1; }
        .gend-wallet-classic .gwc-row-title { font-weight: 800; color: #fff; font-size: .8rem; text-transform: uppercase; letter-spacing: 1.2px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        /* the balance — the engaging metric: glowing accent chip with a live dot */
        .gend-wallet-classic .gwc-row-bal { display: inline-flex; align-items: center; gap: 7px; font-family: "Inter", sans-serif; font-size: .82rem; font-weight: 800; color: var(--gwc-accent); background: color-mix(in srgb, var(--gwc-accent) 13%, rgba(8,11,17,.6)); border: 1px solid color-mix(in srgb, var(--gwc-accent) 38%, transparent); padding: 5px 12px; border-radius: 999px; white-space: nowrap; flex: 0 0 auto; box-shadow: 0 0 18px -8px var(--gwc-accent), inset 0 0 12px -8px var(--gwc-accent); text-shadow: 0 0 10px color-mix(in srgb, var(--gwc-accent) 60%, transparent); }
        .gend-wallet-classic .gwc-row-bal::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: var(--gwc-accent); box-shadow: 0 0 8px var(--gwc-accent); animation: gwcDot 1.8s ease-in-out infinite; flex: 0 0 auto; }
        @keyframes gwcDot { 0%,100% { opacity: .4; transform: scale(.8); } 50% { opacity: 1; transform: scale(1.2); } }
        .gend-wallet-classic .gwc-ctl { display: flex; align-items: center; gap: 8px; position: relative; z-index: 1; }
        .gend-wallet-classic .gwc-cur { color: var(--gwc-accent); font-weight: 900; font-size: 1.05rem; flex: 0 0 auto; }
        .gend-wallet-classic .gwc-input { flex: 1 1 auto; min-width: 0; background: rgba(8,11,17,.72) !important; border: 1px solid rgba(255,255,255,.12) !important; color: #fff !important; border-radius: 10px; padding: 11px 13px !important; font-size: 1.05rem; font-weight: 700; transition: border-color .25s, box-shadow .25s; }
        .gend-wallet-classic .gwc-input:focus { border-color: var(--gwc-accent) !important; box-shadow: 0 0 0 3px color-mix(in srgb, var(--gwc-accent) 22%, transparent), 0 0 18px -4px var(--gwc-accent) !important; outline: none; }
        .gend-wallet-classic .gwc-btn { flex: 0 0 auto; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.14); color: #e2e8f0; border-radius: 10px; padding: 10px 15px; font-weight: 800; font-size: .72rem; text-transform: uppercase; letter-spacing: .5px; cursor: pointer; transition: background .2s, border-color .2s, box-shadow .2s, color .2s, transform .15s; }
        .gend-wallet-classic .gwc-btn[data-act="max"]:hover { background: color-mix(in srgb, var(--gwc-accent) 22%, transparent); border-color: var(--gwc-accent); color: #fff; box-shadow: 0 0 16px -4px var(--gwc-accent); }
        .gend-wallet-classic .gwc-btn:active { transform: scale(.95); }
        .gend-wallet-classic .gwc-btn-ghost:hover { border-color: #f0598a; color: #f0598a; }

        /* remaining to pay */
        .gend-wallet-classic .gwc-due { flex: 0 0 100%; width: 100%; margin: 6px 0 0; padding: 14px 2px 0; border-top: 1px dashed rgba(255,255,255,.12); font-size: .9rem; color: #cbd5e1; font-weight: 700; }
        .gend-wallet-classic .gwc-due strong { color: #fff; font-size: 1.25rem; margin-left: 6px; text-shadow: 0 0 16px rgba(137,194,224,.45); }
        .gend-wallet-classic .gwc-covered, .gend-wallet-classic .gwc-due.gwc-covered { color: #00ff88 !important; text-shadow: 0 0 16px rgba(0,255,136,.5); }

        @media (max-width: 560px) { .gend-wallet-classic .gwc-row { flex: 0 0 100% !important; } }
        @media (prefers-reduced-motion: reduce) {
            .gend-wallet-classic, .gend-wallet-classic > *, .gend-wallet-classic::before, .gwc-badge { animation: none !important; opacity: 1 !important; transform: none !important; }
        }
    ';
    wp_register_style( 'gdc-embed-checkout', false, [], GS_VERSION );
    wp_enqueue_style( 'gdc-embed-checkout' );
    wp_add_inline_style( 'gdc-embed-checkout', $css );
}

// The AI chat widget (LEO/<aipa-widget>) sets its own inline styles and may
// portal a launcher to <body>, so CSS display:none isn't always enough inside
// the embed iframe. Hard-remove it from the DOM (and keep removing for a few
// beats in case it mounts late).
add_action( 'wp_footer', 'gdc_topup_embed_strip_widget', 100001 );
function gdc_topup_embed_strip_widget() {
    if ( empty( $_GET['gend_embed'] ) ) return;
    ?>
    <script>
    (function () {
        function strip() {
            document.querySelectorAll('aipa-widget, leo-widget, [id^="aipa-"], [class*="leo-launcher"], .gs-float-dock, #gs-front-sidebar, #gs-float-menu').forEach(function (n) {
                if (n && n.parentNode) n.parentNode.removeChild(n);
            });
        }
        strip();
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', strip);
        var n = 0, t = setInterval(function () { strip(); if (++n > 12) clearInterval(t); }, 250);
    }());
    </script>
    <?php
}

// Price the task-credit top-up line at the per-credit rate (sales-team's own
// price override only registers inside its AJAX path; we add the product
// ourselves so we must set the price too). Other kinds are priced by their
// owning plugin's callback.
add_action( 'woocommerce_before_calculate_totals', 'gdc_topup_price_task_credits', 20 );
function gdc_topup_price_task_credits( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) return;
    foreach ( $cart->get_cart() as $item ) {
        if ( ! empty( $item['gdc_topup_unit_price'] ) && isset( $item['data'] ) && is_object( $item['data'] ) ) {
            $item['data']->set_price( (float) $item['gdc_topup_unit_price'] );
        }
    }
}

// Auto-complete the digital top-up orders on payment so credits grant instantly
// (leo's AI-credit grant only fires on `completed`; virtual orders otherwise sit
// at `processing`). tasks + DGEN grant on payment_complete already but are
// idempotent, so completing them too is safe.
add_filter( 'woocommerce_payment_complete_order_status', 'gdc_topup_autocomplete', 10, 3 );
function gdc_topup_autocomplete( $status, $order_id, $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) return $status;
    $extra_pid = (int) apply_filters( 'aas_task_checkout_extra_credit_product_id', 0 );
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        $sku     = $product ? $product->get_sku() : '';
        $pid     = (int) $item->get_product_id();
        if ( 'aipa-credits' === $sku || 'gend-dgen-topup' === $sku || ( $extra_pid && $pid === $extra_pid ) ) {
            return 'completed';
        }
    }
    return $status;
}

// ─── Profile nav icons ────────────────────────────────────────────────────────
// Returns a futuristic, dashboard-style SVG icon for a given BP/Youzify nav slug.
// All icons share a unified 24×24 viewBox + currentColor stroke so they inherit
// the active/hover color treatments and stagger animation from the parent.
if ( ! function_exists( 'gdc_get_profile_nav_icon' ) ) {
function gdc_get_profile_nav_icon( $slug ) {
    $key = strtolower( (string) $slug );

    // Aliases — collapse common variants to one canonical icon.
    static $alias = [
        'home'              => 'overview',
        'activity'          => 'overview',
        'profile-overview'  => 'overview',
        'bp-files'          => 'files',
        'portfolio'         => 'files',
        'connections'       => 'friends',
        'wallet'            => 'member-wallet',
        'wallets'           => 'member-wallet',
        'notification'      => 'notifications',
        'notify'            => 'notifications',
        'member-calendar'   => 'calendar',
    ];
    if ( isset( $alias[ $key ] ) ) $key = $alias[ $key ];

    static $icons = [
        // Overview / Activity — heartbeat trace
        'overview'      => '<path d="M3 12h4l2.5-7 5 14 2.5-7H21"/>',
        // Profile — node + ring
        'profile'       => '<circle cx="12" cy="9" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        // Portfolio (Files) — 4-cell dashboard grid
        'files'         => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.2"/>',
        // App Projects (Groups) — isometric cube stack
        'groups'        => '<path d="M12 2 3 7v10l9 5 9-5V7z"/><path d="M3 7l9 5 9-5"/><path d="M12 12v10"/>',
        // Calendar — month grid: framed page, header bar, day dots
        'calendar'      => '<rect x="3" y="4.5" width="18" height="16" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="8" y1="2.5" x2="8" y2="6.5"/><line x1="16" y1="2.5" x2="16" y2="6.5"/><circle cx="8" cy="13" r="1"/><circle cx="12" cy="13" r="1"/><circle cx="16" cy="13" r="1"/><circle cx="8" cy="17" r="1"/><circle cx="12" cy="17" r="1"/>',
        // Connections (Friends) — linked nodes triangle
        'friends'       => '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="18" r="2.5"/><line x1="8" y1="7.5" x2="11" y2="15.5"/><line x1="16" y1="7.5" x2="13" y2="15.5"/><line x1="8.5" y1="6" x2="15.5" y2="6"/>',
        // Messages — chat node with signal pips
        'messages'      => '<path d="M21 12a8 8 0 0 1-12 7l-5 2 1.5-5A8 8 0 1 1 21 12z"/><circle cx="9" cy="12" r="0.9" fill="currentColor"/><circle cx="13" cy="12" r="0.9" fill="currentColor"/><circle cx="17" cy="12" r="0.9" fill="currentColor"/>',
        // Wallet — chip + slot
        'member-wallet' => '<rect x="2.5" y="6.5" width="19" height="13" rx="2"/><path d="M2.5 10.5h19"/><rect x="15" y="14" width="4" height="3" rx="0.8"/>',
        // Settings — orbital sliders
        'settings'      => '<line x1="4" y1="6" x2="20" y2="6"/><circle cx="9" cy="6" r="2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="8" cy="18" r="2"/>',
        // Notifications — beacon
        'notifications' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.5 21a2 2 0 0 1-3 0"/>',
        // Forums — stacked nodes
        'forums'        => '<path d="M21 12a4 4 0 0 1-4 4H9l-4 3V8a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4z"/><line x1="9" y1="10" x2="17" y2="10"/><line x1="9" y1="13" x2="14" y2="13"/>',
        // Media — frame + transmission dot
        'media'         => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        // Blogs / Posts — pulse pad
        'blogs'         => '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="7" y1="9" x2="17" y2="9"/><line x1="7" y1="13" x2="17" y2="13"/><line x1="7" y1="17" x2="13" y2="17"/>',
        // Memberships — ID badge / token card
        'memberships'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11.5" r="2.2"/><path d="M6 17a3 3 0 0 1 6 0"/><line x1="14.5" y1="10" x2="19" y2="10"/><line x1="14.5" y1="13" x2="18" y2="13"/>',
        // My Projects — kanban columns
        'projects'      => '<rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="12" rx="1"/><rect x="17" y="3" width="4" height="7" rx="1"/>',
        // Sales Team — chart-up + person
        'sales-team'    => '<path d="M3 17l5-5 4 4 8-9"/><polyline points="14 7 21 7 21 14"/>',
        // Referral Sales — share/branching arrows
        'referral-sales'=> '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><line x1="8.2" y1="10.8" x2="15.8" y2="6.2"/><line x1="8.2" y1="13.2" x2="15.8" y2="17.8"/>',
        // Invite — user with plus
        'invite'        => '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0"/><line x1="19" y1="6" x2="19" y2="14"/><line x1="15" y1="10" x2="23" y2="10"/>',
        // Contracts (Fund) — signed document
        'invest'        => '<rect x="5" y="3" width="14" height="18" rx="2"/><line x1="8.5" y1="8" x2="15.5" y2="8"/><line x1="8.5" y1="12" x2="15.5" y2="12"/><line x1="8.5" y1="16" x2="13" y2="16"/>',
        // Connections (alias to friends visual, kept explicit for clarity)
        'connections'   => '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="18" r="2.5"/><line x1="8" y1="7.5" x2="11" y2="15.5"/><line x1="16" y1="7.5" x2="13" y2="15.5"/><line x1="8.5" y1="6" x2="15.5" y2="6"/>',
    ];

    $body = isset( $icons[ $key ] )
        ? $icons[ $key ]
        // Fallback — reticle (generic targeting node)
        : '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="1" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="23"/><line x1="1" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="23" y2="12"/>';

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}
}

// ─── Move "Visitors" into the Connections (friends) subnav ────────────────────
// The Youzify visitors plugin registers a top-level Profile nav at slug
// `visitors`. We hide it from our primary nav (above) and add an equivalent
// subnav entry under friends. The `link` parameter points the subnav directly
// at the existing /visitors/ URL so the underlying screen function still runs.
add_action( 'bp_setup_nav', 'gdc_visitors_to_friends_subnav', 999 );
function gdc_visitors_to_friends_subnav() {
    if ( ! function_exists( 'bp_core_new_subnav_item' ) ) return;
    if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'friends' ) ) return;
    if ( ! bp_displayed_user_id() ) return;

    $friends_slug = function_exists( 'bp_get_friends_slug' ) ? bp_get_friends_slug() : 'friends';

    bp_core_new_subnav_item( array(
        'name'                    => __( 'Visitors', 'gend-society' ),
        'slug'                    => 'visitors',
        'parent_slug'             => $friends_slug,
        'parent_url'              => bp_displayed_user_domain() . $friends_slug . '/',
        'link'                    => bp_displayed_user_domain() . 'visitors/',
        'position'                => 90,
        'screen_function'         => '__return_true',
        'show_for_displayed_user' => true,
    ) );
}

// "Activity" appears as a tab on the App Projects page itself
// (see gs_member_groups_tabs_open() in member-profile-pages.php) instead
// of as a BP subnav entry — keeps the tab strip self-contained.

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
            'topup'   => 'tasks',
        ],
        [
            'label'   => 'AI Builder Tokens',
            'value'   => number_format( $ai_tokens, 1 ),
            'color'   => 'var(--gph-blue)',
            'stagger' => 3,
            'topup'   => 'ai',
        ],
        [
            'label'   => '🇨🇦 DGEN Balance',
            'value'   => number_format( $dgen_balance ),
            'color'   => 'var(--gph-green)',
            'stagger' => 4,
            'topup'   => 'dgen',
        ],
        [
            'label'       => '🇨🇦 Store Credits',
            'value'       => '$' . number_format( $store_credits, 2 ),
            'color'       => 'var(--gph-red)',
            'stagger'     => 5,
            'topup'       => 'store',
            'topup_label' => 'Spend',
        ],
    ], $user_id );

    // ── Top-Up wiring ───────────────────────────────────────────────────────
    // Top-Up buttons render only on the viewer's OWN profile (you top up your
    // own wallet). Each card's button launches its own surface:
    //   tasks → the sales-team [task_balance_checkout] modal (pick task qty /
    //           upgrade retainer) — reused verbatim via a hidden trigger.
    //   ai    → a futuristic popup → the existing aipa_buy_credits payment flow.
    //   dgen  → a futuristic DGEN sales page → the new gend_buy_dgen checkout.
    $task_topup_pid = 0;
    if ( class_exists( 'ST_Contract_Settings' ) ) {
        $cs_topup       = ST_Contract_Settings::get();
        $task_topup_pid = (int) ( $cs_topup['retainer_product_id'] ?? 0 );
    }
    // Which top-up surfaces are actually wired up on this install.
    $topup_enabled = [
        'tasks' => ( $task_topup_pid > 0 ),
        'ai'    => class_exists( 'AIPA_Commerce' ),
        // DGEN purchase is hub-only; the product/credit hook lives in C&P.
        'dgen'  => class_exists( 'Gend_CP_DGEN_TopUp' ),
        // Store credits → "Spend" opens the shop (any WooCommerce store).
        'store' => class_exists( 'WooCommerce' ),
    ];

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
    foreach ( $nav_items as $index => $item ) {
        // Change "Groups" to "App Projects"
        if ( $item->slug === 'groups' && strpos( $item->name, 'App Projects' ) === false ) {
            $item->name = str_replace( 'Groups', 'App Projects', $item->name );
        }
    }

    // Remove "Society" + "Visitors" + "Activity" from the primary nav.
    // Visitors → moved under Connections by gdc_visitors_to_friends_subnav()
    // Activity → moved under App Projects (groups) by gdc_activity_to_groups_subnav()
    foreach ( $nav_items as $index => $item ) {
        if ( $item->slug === 'society' || strpos( strip_tags( $item->name ), 'Society' ) !== false ) {
            unset( $nav_items[ $index ] );
            continue;
        }
        if ( $item->slug === 'visitors' || strpos( strip_tags( $item->name ), 'Visitors' ) !== false ) {
            unset( $nav_items[ $index ] );
            continue;
        }
        if ( $item->slug === 'activity' ) {
            unset( $nav_items[ $index ] );
            continue;
        }
        // Remove the "Files"/"Portfolio" (media) tab — its sub-tabs now live
        // under the Overview tab. The underlying media component/route stays
        // registered so the Overview Media iframe (/media/?gdc_tab_only=1) still
        // resolves; we only drop the visible nav entry.
        if ( $item->slug === 'files' || $item->slug === 'bp-files' || $item->slug === 'media'
             || strpos( strip_tags( $item->name ), 'Files' ) !== false
             || strpos( strip_tags( $item->name ), 'Portfolio' ) !== false ) {
            unset( $nav_items[ $index ] );
        }
    }

    // Portfolio (Files/media) tab removed above — just re-index.
    $nav_items = array_values( $nav_items );

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

    // ── Insert "Invest" right after Connections (friends) ──────────────────────
    // Guarantees placement regardless of where Youzify orders the registered tab.
    if ( bp_is_my_profile() ) {
        $invest_obj = (object) [
            'name' => 'Contracts',
            'slug' => 'invest',
            'link' => trailingslashit( bp_displayed_user_domain() ) . 'invest/',
        ];
        // Drop any pre-existing invest entry to avoid duplicates, then re-insert.
        foreach ( $nav_items as $i => $it ) {
            if ( isset( $it->slug ) && $it->slug === 'invest' ) { unset( $nav_items[ $i ] ); }
        }
        $nav_items  = array_values( $nav_items );
        $insert_at  = count( $nav_items );
        foreach ( $nav_items as $i => $it ) {
            if ( isset( $it->slug ) && ( $it->slug === 'friends'
                 || stripos( strip_tags( (string) $it->name ), 'Connection' ) !== false ) ) {
                $insert_at = $i + 1;
                break;
            }
        }
        array_splice( $nav_items, $insert_at, 0, [ $invest_obj ] );
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

        <!-- Cinematic boot sequence: light sweep + top bloom (pure CSS, plays once) -->
        <div class="gdc-boot-fx" aria-hidden="true"></div>

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
                        <?php
                        // Always render the actions row (carrying the id schedule-meeting.js
                        // targets FIRST) so the "Schedule Meeting" button can mount by the
                        // avatar on the member's OWN calendar/profile too — not only when the
                        // Message/Connect links (non-own profile) are present.
                        ?>
                        <div id="gs-profile-actions" class="gdc-identity-actions">
                            <?php if ( $msg_url ) : ?>
                            <a href="<?php echo esc_url( $msg_url ); ?>" class="gdc-action-btn">Message</a>
                            <?php endif; ?>
                            <?php if ( $friend_text ) : ?>
                            <a href="<?php echo esc_url( $friend_url ); ?>" class="gdc-action-btn gdc-action-btn--connect"><?php echo esc_html( $friend_text ); ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 2. Metrics Port ────────────────────────────────────── -->
            <div class="gdc-metrics-port">

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
                        // Details button — surfaced when the viewer is the
                        // group's creator (membership owner). Opens the
                        // existing /my-account/memberships/ detail popup
                        // INLINE on the profile page by fetching the
                        // gdc_membership_modal AJAX endpoint (which already
                        // renders the popup HTML for the memberships
                        // index page) and injecting it into a body-portaled
                        // overlay container.
                        $current_uid  = get_current_user_id();
                        $is_owner     = $current_uid && $admin_group && isset($admin_group->creator_id) && (int) $admin_group->creator_id === $current_uid;
                        $details_mid  = 0;
                        if ($is_owner && function_exists('wu_get_current_customer')) {
                            $cust = wu_get_current_customer();
                            if ($cust && method_exists($cust, 'get_memberships')) {
                                $mems = (array) $cust->get_memberships();
                                if (!empty($mems)) {
                                    $first_mem = reset($mems);
                                    if ($first_mem && method_exists($first_mem, 'get_id')) {
                                        $details_mid = (int) $first_mem->get_id();
                                    }
                                }
                            }
                        }
                        if ($details_mid) {
                            $details_nonce = wp_create_nonce('gdc_membership_modal');
                            $details_ajax  = admin_url('admin-ajax.php');
                            ?>
                            <button type="button"
                                    class="gdc-action-btn gdc-details-btn"
                                    data-gdc-open-details="1"
                                    data-membership-id="<?php echo esc_attr($details_mid); ?>"
                                    data-nonce="<?php echo esc_attr($details_nonce); ?>"
                                    data-ajax="<?php echo esc_attr($details_ajax); ?>"
                                    style="margin-left:8px;background:rgba(120,87,255,.18);color:#c4b5fd;border:1px solid rgba(167,139,250,.35);cursor:pointer;">Details</button>
                            <script>
                            (function(){
                              if (window.__gdcProfileDetailsBound) return;
                              window.__gdcProfileDetailsBound = true;

                              document.addEventListener('click', function(ev){
                                var btn = ev.target && ev.target.closest && ev.target.closest('[data-gdc-open-details="1"]');
                                if (!btn) return;
                                ev.preventDefault();
                                var mid = btn.getAttribute('data-membership-id') || '';
                                if (!mid) return;

                                var existing = document.getElementById('gdc-profile-details-overlay');
                                if (existing) { existing.remove(); }

                                // Iframe the /my-account/memberships/ page with a
                                // query arg that auto-opens the requested
                                // membership's detail modal. The same modal +
                                // tabs + buttons + checkout popup the user gets
                                // when visiting the page directly — no
                                // re-rendering, no re-implementing.
                                var memUrl = '<?php echo esc_js( wc_get_account_endpoint_url('memberships') ); ?>';
                                var sep = memUrl.indexOf('?') === -1 ? '?' : '&';
                                var iframeUrl = memUrl + sep + 'gdc_open_modal=' + encodeURIComponent(mid);

                                var overlay = document.createElement('div');
                                overlay.id = 'gdc-profile-details-overlay';
                                overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483600;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(2,8,23,.85);backdrop-filter:blur(6px);';
                                overlay.addEventListener('click', function(e){ if (e.target === overlay) overlay.remove(); });

                                var dialog = document.createElement('div');
                                dialog.style.cssText = 'position:relative;width:min(1200px,100%);height:min(92vh,1000px);background:#0a1019;border-radius:20px;overflow:hidden;box-shadow:0 60px 160px rgba(0,0,0,.6),0 0 0 1px rgba(148,163,184,.2);display:flex;flex-direction:column;';

                                var closeBtn = document.createElement('button');
                                closeBtn.type = 'button';
                                closeBtn.textContent = '×';
                                closeBtn.style.cssText = 'position:absolute;top:14px;right:14px;background:rgba(15,23,42,.85);color:#f1f5f9;border:1px solid rgba(148,163,184,.3);width:34px;height:34px;border-radius:999px;cursor:pointer;font-size:20px;line-height:1;z-index:2;';
                                closeBtn.addEventListener('click', function(){ overlay.remove(); });
                                dialog.appendChild(closeBtn);

                                var iframe = document.createElement('iframe');
                                iframe.src = iframeUrl;
                                iframe.setAttribute('title', 'Membership details');
                                iframe.style.cssText = 'border:0;width:100%;flex:1;background:#0a1019;';
                                dialog.appendChild(iframe);

                                overlay.appendChild(dialog);
                                document.body.appendChild(overlay);
                              });
                            })();
                            </script>
                            <?php
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Balance grid -->
                <div class="gdc-balance-grid">
                    <?php foreach ( $balances as $b ) :
                        $b_topup = ( $is_own_profile && ! empty( $b['topup'] ) && ! empty( $topup_enabled[ $b['topup'] ] ) )
                            ? $b['topup'] : '';
                    ?>
                    <div class="gdc-kbx gdc-stagger-<?php echo (int) $b['stagger']; ?>"
                         style="--kbx-color: <?php echo esc_attr( $b['color'] ); ?>">
                        <div class="gdc-node-content">
                            <div class="gdc-node-label"><?php echo esc_html( $b['label'] ); ?></div>
                            <div class="gdc-node-value" style="color: <?php echo esc_attr( $b['color'] ); ?>">
                                <?php echo esc_html( $b['value'] ); ?>
                            </div>
                            <?php if ( $b_topup ) : ?>
                            <button type="button"
                                    class="gdc-topup-btn gdc-topup-btn--<?php echo esc_attr( $b_topup ); ?>"
                                    data-gdc-topup="<?php echo esc_attr( $b_topup ); ?>"
                                    style="--gph-accent: <?php echo esc_attr( $b['color'] ); ?>;">
                                <span class="gdc-topup-btn__plus" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                </span>
                                <span class="gdc-topup-btn__label"><?php echo esc_html( $b['topup_label'] ?? 'Top Up' ); ?></span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div><!-- .gdc-metrics-port -->

        </div><!-- .gdc-profile-hub -->

        <!-- ── 3. Nav Bar ────────────────────────────────────────────── -->
        <?php
        // Hide the in-page profile tab strip when:
        //   - viewer has no WP-admin (edit_posts) access
        //   - they're on their OWN profile
        //   - the social plugin is active (so the sidebar already mirrors these tabs)
        // Backend-access users always see the strip; non-backend users still see
        // it on OTHER members' profiles, where the sidebar is "their" menu.
        $gs_hide_profile_nav = function_exists( 'gs_frontend_bar_is_profile_mode' )
            && gs_frontend_bar_is_profile_mode()
            && bp_is_my_profile();
        ?>
        <?php if ( ! $gs_hide_profile_nav ) : ?>
        <nav class="gdc-profile-nav" id="gdc-profile-nav" aria-label="Profile navigation">
            <div class="gdc-profile-nav-inner">
                <?php foreach ( $nav_items as $i => $item ) :
                    $is_active = ( $current_component === $item->slug );
                ?>
                <a href="<?php echo esc_url( $item->link ); ?>"
                   class="gdc-nav-item<?php echo $is_active ? ' gdc-nav-item--active' : ''; ?>"
                   style="--gdc-nav-i: <?php echo (int) $i; ?>">
                    <span class="gdc-nav-icon" aria-hidden="true"><?php echo gdc_get_profile_nav_icon( $item->slug ); ?></span>
                    <span class="gdc-nav-text"><?php echo wp_kses( $item->name, [ 'span' => [ 'class' => true ] ] ); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </nav>
        <?php endif; ?>

    </section>

    <?php if ( $is_own_profile ) :
        $gdc_task_credit_rate = 50.0;
        if ( class_exists( 'ST_Contract_Settings' ) ) {
            $cs_rate              = ST_Contract_Settings::get();
            $gdc_task_credit_rate = (float) ( $cs_rate['credit_value'] ?? 50.0 );
        }
    ?>
    <!-- ── Top-Up popups ───────────────────────────────────────────────────
         Each header Top-Up button opens a futuristic popup to pick an amount,
         then navigates (GET) to gdc_handle_wallet_topup_purchase (template_
         redirect) which adds the right product to the cart and sends the buyer
         to the normal WooCommerce checkout (any configured store gateway).    -->
    <script id="gdc-topup">
    (function () {
        if ( window.__gdcTopupBound ) return;
        window.__gdcTopupBound = true;

        var CFG = <?php echo wp_json_encode( [
            'tasks'       => ! empty( $topup_enabled['tasks'] ),
            'ai'          => ! empty( $topup_enabled['ai'] ),
            'dgen'        => ! empty( $topup_enabled['dgen'] ),
            'store'       => ! empty( $topup_enabled['store'] ),
            'shopUrl'     => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
            'hasWalletCard' => (bool) ( $is_own_profile && shortcode_exists( 'gend_wallet' ) ),
            'sym'         => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$',
            'taskBalance' => (float) $task_credits,
            'aiBalance'   => (float) $ai_tokens,
            'dgenBalance' => (float) $dgen_balance,
            'storeBalance'=> (float) $store_credits,
            'creditValue' => (float) $gdc_task_credit_rate,
            'retainerUrl' => ( $task_topup_pid && function_exists( 'get_permalink' ) ) ? ( get_permalink( $task_topup_pid ) ?: '' ) : '',
            'checkoutUrl' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ),
            'nonce'       => wp_create_nonce( 'gdc_topup' ),
        ] ); ?>;

        // Point the iframe STRAIGHT at the checkout with the top-up params: the
        // template_redirect handler populates the cart on that same request and
        // lets the checkout render (one WP boot, no 302 round-trip).
        function buyUrlFor(kind, params) {
            var b = CFG.checkoutUrl;
            var u = b + (b.indexOf('?') === -1 ? '?' : '&') +
                'gdc_topup=' + encodeURIComponent(kind) + '&_n=' + encodeURIComponent(CFG.nonce) + '&gend_embed=1';
            for (var k in params) { if (params.hasOwnProperty(k)) u += '&' + k + '=' + encodeURIComponent(params[k]); }
            return u;
        }
        var ACCENT = { tasks: 'var(--gph-magenta)', ai: 'var(--gph-blue)', dgen: 'var(--gph-green)' };

        // Swap the popup for an embedded secure-checkout view (real WC checkout
        // with live payment methods, same-origin iframe).
        function goCheckout(kind, params) {
            var url = buyUrlFor(kind, params);
            var accent = ACCENT[kind] || 'var(--gph-blue)';
            var d = el('div', 'gdc-tp-dialog gdc-tp-dialog--checkout');
            d.style.setProperty('--gph-accent', accent);
            d.appendChild(el('div', 'gdc-tp-scan'));
            var close = el('button', 'gdc-tp-close', '&times;');
            close.type = 'button'; close.setAttribute('aria-label', 'Close');
            close.addEventListener('click', closeOverlay);
            d.appendChild(close);
            var bar = el('div', 'gdc-tp-cobar', '<span class="gdc-tp-cobar__dot"></span> Secure Checkout');
            d.appendChild(bar);
            var loading = el('div', 'gdc-tp-loading', '<div class="gdc-tp-spinner"></div><p class="gdc-tp-loadmsg">Preparing your secure checkout&hellip;</p>');
            d.appendChild(loading);
            // Cycle reassuring messages so the load feels active (the hub render
            // takes a few seconds).
            var msgs = ['Preparing your secure checkout…', 'Loading payment methods…', 'Encrypting your connection…', 'Almost ready…'];
            var mp = loading.querySelector('.gdc-tp-loadmsg'), mi = 0;
            var cyc = setInterval(function () { mi = (mi + 1) % msgs.length; if (mp) mp.textContent = msgs[mi]; }, 1400);
            var frame = el('iframe', 'gdc-tp-frame');
            frame.setAttribute('title', 'Secure checkout');
            frame.setAttribute('allow', 'payment *');
            frame.addEventListener('load', function () { clearInterval(cyc); loading.style.display = 'none'; frame.classList.add('is-loaded'); });
            frame.src = url;
            d.appendChild(frame);
            var fb = el('div', 'gdc-tp-foot', 'Trouble loading? <a href="' + url + '" target="_blank" rel="noopener">Open checkout in a new tab &rarr;</a>');
            d.appendChild(fb);
            mount(d, accent);
        }

        // ── Store Credits → Spend: a big cinematic popup that embeds /shop ──
        // Same-origin, so we reach into the iframe to (a) strip the site chrome
        // we don't want in the embed and (b) scroll-reveal each product.

        // Hide the top header (logo + hamburger) and the bottom-right chat bubble,
        // and the bottom-left profile orb — but KEEP the floating cart.
        function styleShopEmbed(frame) {
            var doc, win;
            try { win = frame.contentWindow; doc = frame.contentDocument || win.document; } catch (e) { return; }
            if (!doc || !doc.head) return;
            if (!doc.getElementById('gdc-shop-embed')) {
                var css = doc.createElement('style');
                css.id = 'gdc-shop-embed';
                css.textContent =
                    /* top header: gend.me logo (top-left) + hamburger (top-right) */
                    '#main-3d-header,.header-anchor-wrap{display:none!important;}' +
                    /* AI / chat widget bubble (bottom-right) */
                    'aipa-widget,leo-widget,[class*="leo-launcher"],[class*="chat-launcher"],' +
                    '[id*="chat-launcher"],[class*="cf-launcher"],[id*="cf-launcher"],' +
                    '[class*="chat-widget"],[id*="chat-widget"]{display:none!important;}' +
                    'html,body{margin-top:0!important;padding-top:0!important;}';
                doc.head.appendChild(css);
            }
            // The profile orb is a fixed/sticky launcher carrying a user avatar,
            // sitting by the cart. Hide it; never touch anything cart-related.
            function sweep() {
                var nodes;
                try { nodes = doc.querySelectorAll('body > *, body > * > *'); } catch (e) { return; }
                Array.prototype.forEach.call(nodes, function (n) {
                    var cs;
                    try { cs = win.getComputedStyle(n); } catch (e) { return; }
                    if (!cs || (cs.position !== 'fixed' && cs.position !== 'sticky')) return;
                    var key = (n.id || '') + ' ' + (n.className && n.className.toString ? n.className.toString() : '');
                    if (/cart/i.test(key)) return;                       // keep the cart
                    var img = n.querySelector && n.querySelector('img');
                    var isProfile = /profile|account|avatar|user-orb|gs-user/i.test(key) ||
                        (img && /avatar|gravatar|\/uploads\/avatars|user-photo|profile/i.test(img.getAttribute('src') || ''));
                    if (isProfile) n.style.setProperty('display', 'none', 'important');
                });
            }
            sweep(); setTimeout(sweep, 800); setTimeout(sweep, 2200);    // catch late mounts
        }

        // The shop is same-origin, so once it loads we reach into the iframe and
        // give every product a staggered scroll-reveal as it enters view.
        function injectShopReveal(frame) {
            var doc, win;
            try { win = frame.contentWindow; doc = frame.contentDocument || win.document; } catch (e) { return; }
            if (!doc || !doc.head) return;
            var st = doc.createElement('style');
            st.textContent =
                '.gdc-reveal{opacity:0;transform:translateY(34px) scale(0.985);' +
                'transition:opacity .7s cubic-bezier(.16,1,.3,1),transform .7s cubic-bezier(.16,1,.3,1);will-change:opacity,transform;}' +
                '.gdc-reveal.gdc-in{opacity:1;transform:none;}' +
                '@media (prefers-reduced-motion: reduce){.gdc-reveal{opacity:1!important;transform:none!important;transition:none!important;}}';
            doc.head.appendChild(st);
            var sel = 'ul.products li.product, li.product, .wc-block-grid__product, ' +
                      '.products .product, .wp-block-woocommerce-product-template li, .wc-block-product';
            var items = doc.querySelectorAll(sel);
            if (!items.length) return;
            var IO = win.IntersectionObserver || window.IntersectionObserver;
            if (!IO) { Array.prototype.forEach.call(items, function (it) { it.classList.add('gdc-reveal', 'gdc-in'); }); return; }
            var io = new IO(function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    var idx = +(en.target.getAttribute('data-gdc-i') || 0);
                    en.target.style.transitionDelay = (Math.min(idx, 6) * 0.07) + 's';
                    en.target.classList.add('gdc-in');
                    io.unobserve(en.target);
                });
            }, { threshold: 0.06 });
            Array.prototype.forEach.call(items, function (it, i) {
                it.classList.add('gdc-reveal');
                it.setAttribute('data-gdc-i', i % 8);
                io.observe(it);
            });
        }

        function openShop() {
            var accent = 'var(--gph-red)';
            var d = el('div', 'gdc-tp-dialog gdc-tp-dialog--shop');
            d.style.setProperty('--gph-accent', accent);
            d.appendChild(el('div', 'gdc-tp-scan'));
            var close = el('button', 'gdc-tp-close', '&times;');
            close.type = 'button'; close.setAttribute('aria-label', 'Close');
            close.addEventListener('click', closeOverlay);
            d.appendChild(close);
            var bar = el('div', 'gdc-tp-cobar',
                '<span class="gdc-tp-cobar__dot"></span> Spend Store Credits &middot; ' +
                '<strong style="color:var(--gph-accent)">' + CFG.sym + fmt(CFG.storeBalance) + '</strong> available');
            d.appendChild(bar);
            var loading = el('div', 'gdc-tp-loading', '<div class="gdc-tp-spinner"></div><p class="gdc-tp-loadmsg">Opening the shop&hellip;</p>');
            d.appendChild(loading);
            var msgs = ['Opening the shop…', 'Loading the latest products…', 'Applying your store credit…', 'Almost ready…'];
            var mp = loading.querySelector('.gdc-tp-loadmsg'), mi = 0;
            var cyc = setInterval(function () { mi = (mi + 1) % msgs.length; if (mp) mp.textContent = msgs[mi]; }, 1400);
            var frame = el('iframe', 'gdc-tp-frame gdc-tp-frame--shop');
            frame.setAttribute('title', 'Shop');

            // Reveal as soon as the shop's DOM is PARSED — don't wait for the
            // full `load` (which blocks on every image + the chat widget bundle,
            // ~15-20s on a heavy WP page). Same-origin lets us watch readyState.
            var revealed = false, poll, hard;
            function reveal() {
                if (revealed) return; revealed = true;
                clearInterval(cyc); clearInterval(poll); clearTimeout(hard);
                loading.style.display = 'none';
                frame.classList.add('is-loaded');
                styleShopEmbed(frame);
                injectShopReveal(frame);
            }
            poll = setInterval(function () {
                var rs;
                try { rs = frame.contentDocument && frame.contentDocument.readyState; } catch (e) { rs = null; }
                if (rs === 'interactive' || rs === 'complete') reveal();
            }, 150);
            frame.addEventListener('load', reveal);           // fallback / cross-origin
            hard = setTimeout(reveal, 12000);                 // never hang the loader
            frame.src = CFG.shopUrl;
            d.appendChild(frame);
            var fb = el('div', 'gdc-tp-foot', 'Store credit applies at checkout &middot; <a href="' + CFG.shopUrl + '" target="_blank" rel="noopener">Open the shop in a new tab &rarr;</a>');
            d.appendChild(fb);
            mount(d, accent);
        }

        // ── Helpers ──────────────────────────────────────────────────────
        function el(tag, cls, html) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (html != null) n.innerHTML = html;
            return n;
        }
        function fmt(n) {
            return (Math.round(n * 100) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 });
        }
        function closeOverlay() {
            var o = document.getElementById('gdc-topup-overlay');
            // Return the (bound) wallet node to its hidden home so it survives the
            // overlay removal and stays wired for the next open.
            if (o) {
                var w = o.querySelector('.gend-wallet');
                var home = document.getElementById('gdc-wallet-home');
                if (w && home) home.appendChild(w);
                o.classList.add('is-closing');
                setTimeout(function(){ o.remove(); }, 220);
            }
            document.removeEventListener('keydown', onEsc);
            // Release the body scroll-lock taken in mount().
            document.documentElement.classList.remove('gdc-tp-lock');
        }
        function onEsc(e) { if (e.key === 'Escape') closeOverlay(); }

        // Mounts a dialog node inside a fresh body-portaled overlay.
        function mount(dialog, accent) {
            closeOverlay();
            // Ease any header card that was mid-hover back to flat — otherwise the
            // overlay swallows its pointerout and it stays frozen/tilted (oversized)
            // behind the modal, bleeding through the glass and adding page scrollbars.
            document.dispatchEvent(new Event('gdc:release-tilt'));
            // Lock the page so the (tall) dialog scrolls itself, not the page.
            document.documentElement.classList.add('gdc-tp-lock');
            var overlay = el('div');
            overlay.id = 'gdc-topup-overlay';
            overlay.className = 'gdc-tp-overlay';
            if (accent) overlay.style.setProperty('--gph-accent', accent);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) closeOverlay(); });
            overlay.appendChild(dialog);
            document.body.appendChild(overlay);
            // Force reflow so the entrance transition runs.
            void overlay.offsetWidth;
            overlay.classList.add('is-open');
            document.addEventListener('keydown', onEsc);
        }

        // Builds the shared dialog shell. `rows` is an array of HTMLElements that
        // animate in one after another ("builds itself").
        function dialog(accent, rows) {
            var d = el('div', 'gdc-tp-dialog');
            d.style.setProperty('--gph-accent', accent);
            var scan = el('div', 'gdc-tp-scan'); d.appendChild(scan);
            var close = el('button', 'gdc-tp-close', '&times;');
            close.type = 'button';
            close.setAttribute('aria-label', 'Close');
            close.addEventListener('click', closeOverlay);
            d.appendChild(close);
            rows.forEach(function (r, i) {
                r.classList.add('gdc-tp-row');
                r.style.setProperty('--i', i);
                d.appendChild(r);
            });
            return d;
        }

        // Amount selector shared by the AI + DGEN popups. Returns {node, get}.
        function amountField(picks, unit, onChange) {
            var wrap = el('div', 'gdc-tp-amount');
            var chips = el('div', 'gdc-tp-chips');
            var custom = el('input', 'gdc-tp-custom');
            custom.type = 'number'; custom.min = '1'; custom.step = '1';
            custom.placeholder = 'Custom amount';
            var state = { val: picks[1] || picks[0] || 50 };

            function select(v) {
                state.val = v;
                Array.prototype.forEach.call(chips.children, function (c) {
                    c.classList.toggle('is-active', parseFloat(c.dataset.v) === v);
                });
                if (parseFloat(custom.value) !== v) custom.value = '';
                onChange && onChange(state.val);
            }
            picks.forEach(function (v) {
                var c = el('button', 'gdc-tp-chip', fmt(v) + ' <small>' + unit + '</small>');
                c.type = 'button'; c.dataset.v = v;
                c.addEventListener('click', function () { select(v); });
                chips.appendChild(c);
            });
            custom.addEventListener('input', function () {
                var v = parseFloat(custom.value);
                if (!isNaN(v) && v > 0) {
                    state.val = v;
                    Array.prototype.forEach.call(chips.children, function (c) { c.classList.remove('is-active'); });
                    onChange && onChange(state.val);
                }
            });
            wrap.appendChild(chips);
            wrap.appendChild(custom);
            // default selection
            setTimeout(function () { select(state.val); }, 0);
            return { node: wrap, get: function () { return state.val; } };
        }

        function cta(label, accent, onClick) {
            var b = el('button', 'gdc-tp-cta', '<span>' + label + '</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>');
            b.type = 'button';
            b.addEventListener('click', onClick);
            return b;
        }

        // ── AI Builder Tokens ──────────────────────────────────────────────
        function openAI() {
            var head = el('div', 'gdc-tp-head',
                '<span class="gdc-tp-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a4 4 0 0 1 4 4c1.5.5 3 1.8 3 4a4 4 0 0 1-1 2.6A4 4 0 0 1 16 22H8a4 4 0 0 1-2-7.4A4 4 0 0 1 5 12c0-2.2 1.5-3.5 3-4a4 4 0 0 1 4-4z"/><circle cx="9" cy="11" r="1" fill="currentColor"/><circle cx="15" cy="11" r="1" fill="currentColor"/></svg></span>' +
                '<div><h2>Top Up AI Builder Tokens</h2><p class="gdc-tp-tag">Fuel every build, edit, and generation with more tokens.</p></div>');
            var bal = el('div', 'gdc-tp-balance', 'Current balance &middot; <strong>' + fmt(CFG.aiBalance) + '</strong> tokens');
            var amtLabel = el('div', 'gdc-tp-label', 'How many tokens would you like?');
            var totalRow = el('div', 'gdc-tp-total', '');
            function paint(v) { totalRow.innerHTML = 'Total <strong>' + CFG.sym + fmt(v) + '</strong> <small>' + CFG.sym + '1.00 / token</small>'; }
            var amt = amountField([500, 1000, 2500, 5000], 'tok', paint);
            var go = cta('Continue to Secure Checkout', 'var(--gph-blue)', function () {
                goCheckout('ai', { amount: Math.max(1, Math.round(amt.get())) });
            });
            var foot = el('div', 'gdc-tp-foot', 'Secure checkout &middot; billed in CAD &middot; tokens credited instantly on payment.');
            mount(dialog('var(--gph-blue)', [head, bal, amtLabel, amt.node, totalRow, go, foot]), 'var(--gph-blue)');
        }

        // ── DGEN Balance — sales page ───────────────────────────────────────
        function openDGEN() {
            var head = el('div', 'gdc-tp-head',
                '<span class="gdc-tp-badge">&#127464;&#127462;</span>' +
                '<div><h2>Buy DGEN</h2><p class="gdc-tp-tag">Your in-network currency. <strong>1 DGEN = ' + CFG.sym + '1.00 CAD.</strong></p></div>');
            var bal = el('div', 'gdc-tp-balance', 'Current balance &middot; <strong>' + fmt(CFG.dgenBalance) + '</strong> DGEN');
            var benefits = el('div', 'gdc-tp-benefits',
                '<div class="gdc-tp-benefit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="5" y1="19" x2="19" y2="5"/></svg><span><strong>0% transaction fees</strong>Spend DGEN anywhere on the network, fee-free.</span></div>' +
                '<div class="gdc-tp-benefit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l5-5 4 4 8-9"/><polyline points="14 7 21 7 21 14"/></svg><span><strong>Earns Hold-Accelerator interest</strong>Held DGEN accrues yDGEN yield automatically.</span></div>' +
                '<div class="gdc-tp-benefit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="12" rx="2"/><path d="M7 8V6a5 5 0 0 1 10 0v2"/></svg><span><strong>Unlocks Fund Token investing</strong>Use DGEN to buy Fund Tokens and profit-share.</span></div>');
            var amtLabel = el('div', 'gdc-tp-label', 'Buy as many DGEN as you like');
            var totalRow = el('div', 'gdc-tp-total', '');
            function paint(v) { totalRow.innerHTML = 'Total <strong>' + CFG.sym + fmt(v) + '</strong> <small>' + fmt(v) + ' DGEN</small>'; }
            var amt = amountField([50, 100, 250, 500, 1000], 'DGEN', paint);
            var go = cta('Continue to Secure Checkout', 'var(--gph-green)', function () {
                goCheckout('dgen', { amount: Math.max(1, Math.round(amt.get())) });
            });
            var foot = el('div', 'gdc-tp-foot', 'Pay with card, crypto, or any store gateway &middot; DGEN credited on payment.');
            var rows = [];
            // The live wallet card (balance + Exchange/Transfer/Withdraw/Spend/
            // History, fully functional) sits at the very top with its own
            // staggered entrance. We relocate the page's bound .gend-wallet node
            // into this placeholder so its action buttons keep working.
            var walletSlot = null;
            if (CFG.hasWalletCard) { walletSlot = el('div', 'gdc-tp-walletcard'); rows.push(walletSlot); }
            rows.push(head, bal, benefits, amtLabel, amt.node, totalRow, go, foot);
            var dlg = dialog('var(--gph-green)', rows);
            if (CFG.hasWalletCard) dlg.classList.add('gdc-tp-dialog--wallet');
            mount(dlg, 'var(--gph-green)');
            // Move the bound wallet into the popup (after it is in the DOM).
            if (walletSlot) {
                var home = document.getElementById('gdc-wallet-home');
                var w = home && home.querySelector('.gend-wallet');
                if (w) { walletSlot.appendChild(w); }
            }
        }

        // ── Task Credits — pick how many credits to buy (or upgrade retainer) ─
        function openTasks() {
            var rate = CFG.creditValue || 50;
            var head = el('div', 'gdc-tp-head',
                '<span class="gdc-tp-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M21 12v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h11"/></svg></span>' +
                '<div><h2>Top Up Task Credits</h2><p class="gdc-tp-tag">On-demand dev support — 1 credit covers one request (CSS / code / feature, up to 30 min).</p></div>');
            var bal = el('div', 'gdc-tp-balance', 'Current balance &middot; <strong>' + fmt(CFG.taskBalance) + '</strong> credits');
            var amtLabel = el('div', 'gdc-tp-label', 'How many task credits would you like?');
            var totalRow = el('div', 'gdc-tp-total', '');
            function paint(v) { totalRow.innerHTML = 'Total <strong>' + CFG.sym + fmt(v * rate) + '</strong> <small>' + fmt(v) + ' &times; ' + CFG.sym + fmt(rate) + '</small>'; }
            var amt = amountField([1, 5, 10, 25], 'credits', paint);
            var go = cta('Continue to Secure Checkout', 'var(--gph-magenta)', function () {
                goCheckout('tasks', { qty: Math.max(1, Math.round(amt.get())) });
            });
            var rows = [head, bal, amtLabel, amt.node, totalRow, go];
            if (CFG.retainerUrl) {
                var alt = el('div', 'gdc-tp-alt', 'Prefer a monthly plan? <a href="' + CFG.retainerUrl + '">Upgrade your retainer &rarr;</a>');
                rows.push(alt);
            }
            rows.push(el('div', 'gdc-tp-foot', 'Secure checkout &middot; credits added to your wallet on payment.'));
            mount(dialog('var(--gph-magenta)', rows), 'var(--gph-magenta)');
        }

        // ── Delegate clicks from the header buttons ─────────────────────────
        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('[data-gdc-topup]');
            if (!btn) return;
            e.preventDefault();
            var kind = btn.getAttribute('data-gdc-topup');
            if (kind === 'tasks' && CFG.tasks) return openTasks();
            if (kind === 'ai' && CFG.ai) return openAI();
            if (kind === 'dgen' && CFG.dgen) return openDGEN();
            if (kind === 'store' && CFG.store) return openShop();
        });

        // The embedded wallet card's action buttons (Exchange/Transfer/Withdraw/
        // Spend/History) → open the wallet modal directly via the reward-programs
        // exposed API. Robust regardless of whether the relocated jQuery binding
        // survived the move into the popup. Capture-phase + stopPropagation so it
        // fires exactly once even if the wallet's own handler also moved in.
        document.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('.gdc-tp-walletcard [data-modal-action]');
            if (!b) return;
            e.preventDefault();
            e.stopPropagation();
            if (window.GendWallet && typeof window.GendWallet.openModal === 'function') {
                window.GendWallet.openModal(
                    b.getAttribute('data-point-type') || 'transact',
                    b.getAttribute('data-modal-action') || 'overview'
                );
            }
        }, true);
    }());
    </script>
    <?php endif; ?>

    <!-- ── Sub-nav icon injector ───────────────────────────────────────────
         BP's sub-navs (Friendships/Requests, Inbox/Sent/Compose, General/
         Notifications/Account, etc.) are rendered by core templates, so we
         enhance them client-side: detect each <li>, derive its slug from the
         BP-generated id (`{component}-{slug}-li`), and prepend a futuristic
         icon chip that matches the primary-nav styling. Re-runs after BP's
         AJAX tab switches so newly-rendered sub-navs get treated too. ── -->
    <script id="gdc-subnav-icons">
    (function () {
        if ( window.__gdcSubnavBound ) return;
        window.__gdcSubnavBound = true;

        var ICONS = {
            // Friends / Connections
            'my-friends':         '<circle cx="9" cy="10" r="3"/><circle cx="17" cy="12" r="2.5"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M14 19a5 5 0 0 1 8 0"/>',
            'friendships':        '<circle cx="9" cy="10" r="3"/><circle cx="17" cy="12" r="2.5"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M14 19a5 5 0 0 1 8 0"/>',
            'requests':           '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0"/><line x1="18" y1="7" x2="18" y2="13"/><line x1="15" y1="10" x2="21" y2="10"/>',
            'invitations':        '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0"/><line x1="18" y1="7" x2="18" y2="13"/><line x1="15" y1="10" x2="21" y2="10"/>',
            'visitors':           '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',

            // Messages
            'inbox':              '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5 4h14l3 8v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-7z"/>',
            'sentbox':            '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
            'sent':               '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
            'compose':            '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z"/>',
            'notices':            '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.5 21a2 2 0 0 1-3 0"/>',
            'starred':            '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',

            // Activity
            'just-me':            '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'personal':           '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'mentions':           '<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>',
            'favorites':          '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'favourites':         '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'groups':             '<path d="M12 2 3 7v10l9 5 9-5V7z"/><path d="M3 7l9 5 9-5"/><path d="M12 12v10"/>',

            // Groups (My Groups subnav)
            'my-groups':          '<path d="M12 2 3 7v10l9 5 9-5V7z"/><path d="M3 7l9 5 9-5"/><path d="M12 12v10"/>',
            'invites':            '<rect x="2" y="5" width="20" height="14" rx="2"/><polyline points="22 7 12 13 2 7"/>',
            'pending':            '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>',

            // Profile
            'public':             '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'edit':               '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z"/>',
            'change-avatar':      '<circle cx="12" cy="13" r="4"/><path d="M5 8h2l1.5-3h7L17 8h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2z"/>',
            'change-cover-image': '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'change-cover':       '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',

            // Settings
            'general':            '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
            'notifications':      '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.5 21a2 2 0 0 1-3 0"/>',
            'capabilities':       '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'delete-account':     '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
            'export':             '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'data':               '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v6c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 11v6c0 1.7 4 3 9 3s9-1.3 9-3v-6"/>',

            // Visitors plugin subnav (Recent / Analytics / History)
            'recent':             '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>',
            'analytics':          '<polyline points="3 17 9 11 13 15 21 7"/><polyline points="14 7 21 7 21 14"/>',
            'history':            '<path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 9 8 9"/><polyline points="12 7 12 12 15 14"/>',

            // Wallet subnavs (myCred / store credits / history)
            'transactions':       '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
            'transfer':           '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
            'orders':             '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>',
            'downloads':          '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',

            // Forum (bbPress)
            'topics':             '<path d="M21 12a4 4 0 0 1-4 4H9l-4 3V8a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4z"/>',
            'replies':            '<polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
            'subscriptions':      '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>',
            'engagements':        '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        };

        function makeIcon(slug) {
            var body = ICONS[slug] || '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/>';
            return '<span class="gdc-subnav-icon" aria-hidden="true">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' +
                body + '</svg></span>';
        }

        // Extract canonical slug from a BP subnav <li>.
        // BP ids follow `{component}-{slug}-li` (sometimes
        // `{component}-{slug}-personal-li`). Falls back to the last
        // non-empty URL segment.
        function slugFromLi(li) {
            var id = li.id || '';
            // Strip trailing -li
            var s = id.replace(/-li$/, '');
            // Strip leading component prefix — split at first dash
            var dash = s.indexOf('-');
            if (dash > 0) s = s.substring(dash + 1);
            // Some BP nav ids end with `-personal` — drop it
            s = s.replace(/-personal$/, '');
            if (s) return s;

            var a = li.querySelector('a');
            if (!a) return '';
            var href = (a.getAttribute('href') || '').replace(/[\?#].*$/, '').replace(/\/+$/,'');
            var parts = href.split('/');
            return parts[parts.length - 1] || '';
        }

        function inject(scope) {
            scope = scope || document;
            // Target every BP subnav container we know about
            var selectors = [
                '#subnav.item-list-tabs li',
                '.item-body nav.bp-navs li',
                '.item-body .bp-navs li',
                '.youzify-tabs-nav li',
                'div.item-list-tabs.no-ajax li'
            ];
            var lis = scope.querySelectorAll(selectors.join(','));

            // Stagger index per subnav container, not per page
            var counters = new WeakMap();
            lis.forEach(function (li) {
                // Skip the main profile nav (already iconified server-side)
                if (li.closest('.gdc-profile-nav')) return;
                var a = li.querySelector('a');
                if (!a) return;
                if (a.querySelector('.gdc-subnav-icon')) return;

                var slug = slugFromLi(li);
                a.insertAdjacentHTML('afterbegin', makeIcon(slug));

                // Stagger relative to parent UL so each subnav animates fresh
                var parent = li.parentElement || li;
                var i = counters.get(parent) || 0;
                li.style.setProperty('--gdc-subnav-i', i);
                counters.set(parent, i + 1);
            });
        }

        function run() { inject(document); }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }

        // BP swaps subnav contents over AJAX; re-inject after a short beat
        document.addEventListener('click', function (e) {
            if (e.target.closest('#subnav a, .item-body .bp-navs a, .youzify-tabs-nav a')) {
                setTimeout(run, 250);
                setTimeout(run, 800);
            }
        });

        // Observe DOM mutations inside the BP item body (covers
        // ajax-replaced content where the click may originate elsewhere).
        var body = document.querySelector('#item-body, .youzify-page-main-content');
        if (body && window.MutationObserver) {
            var mo = new MutationObserver(function () { run(); });
            mo.observe(body, { childList: true, subtree: true });
        }
    }());
    </script>
    <script id="gdc-kbx-tilt">
    (function () {
        if (window.__gdcKbxTilt) return;        // bind once per page
        window.__gdcKbxTilt = true;

        var reduce = window.matchMedia &&
                     window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce) return;

        var SEL  = '.gdc-profile-uplink .gdc-kbx';
        var MAX  = 7;        // max pitch / yaw in degrees — restrained = elegant
        var LERP = 0.12;     // easing factor; lower = silkier, longer glide
        var active = new Map();
        var raf = null;

        function glareFor(el) {
            var g = el.querySelector(':scope > .gdc-kbx-glare');
            if (!g) {
                g = document.createElement('i');
                g.className = 'gdc-kbx-glare';
                el.appendChild(g);
            }
            return g;
        }
        function stateFor(el) {
            var s = active.get(el);
            if (!s) {
                s = {
                    cur: { rx: 0, ry: 0, mx: 50, my: 50, lift: 0 },
                    tgt: { rx: 0, ry: 0, mx: 50, my: 50, lift: 0 },
                    glare: glareFor(el)
                };
                active.set(el, s);
            }
            return s;
        }

        function loop() {
            raf = null;
            var running = false;
            active.forEach(function (s, el) {
                var c = s.cur, t = s.tgt;
                c.rx   += (t.rx   - c.rx)   * LERP;
                c.ry   += (t.ry   - c.ry)   * LERP;
                c.mx   += (t.mx   - c.mx)   * LERP;
                c.my   += (t.my   - c.my)   * LERP;
                c.lift += (t.lift - c.lift) * LERP;

                // settled at rest → clean up so the resting neon spin returns
                if (t.lift === 0 && c.lift < 0.003 &&
                    Math.abs(c.rx) < 0.02 && Math.abs(c.ry) < 0.02) {
                    el.classList.remove('gdc-kbx--live');
                    el.style.removeProperty('transform');
                    el.style.removeProperty('box-shadow');
                    el.style.removeProperty('--kbx-lift');
                    el.style.removeProperty('--kbx-mx');
                    el.style.removeProperty('--kbx-my');
                    s.glare.style.opacity = '0';
                    active.delete(el);
                    return;
                }
                running = true;

                var lift = c.lift;
                // !important so the tilt beats the gdcKbxEnter entrance animation,
                // which (with fill: forwards) otherwise outranks inline transform
                // in the cascade and silently flattens the metrics/balance cards.
                el.style.setProperty('transform',
                    'perspective(1100px) rotateX(' + c.rx.toFixed(3) + 'deg) rotateY(' +
                    c.ry.toFixed(3) + 'deg) translateZ(0) scale(' +
                    (1 + 0.026 * lift).toFixed(4) + ')', 'important');
                el.style.setProperty('box-shadow',
                    '0 ' + (30 * lift).toFixed(1) + 'px ' + (66 * lift).toFixed(1) +
                    'px -24px color-mix(in srgb, var(--kbx-color, var(--gph-blue)) 70%, transparent)');
                el.style.setProperty('--kbx-mx', c.mx.toFixed(2) + '%');
                el.style.setProperty('--kbx-my', c.my.toFixed(2) + '%');
                el.style.setProperty('--kbx-lift', lift.toFixed(3));
                s.glare.style.opacity = lift.toFixed(3);
            });
            if (running) raf = requestAnimationFrame(loop);
        }
        function kick() { if (!raf) raf = requestAnimationFrame(loop); }

        // When a top-up popup opens, ease every active card back to rest so none
        // freeze mid-tilt behind the modal (the overlay eats their pointerout).
        document.addEventListener('gdc:release-tilt', function () {
            active.forEach(function (s) {
                s.tgt.rx = 0; s.tgt.ry = 0; s.tgt.mx = 50; s.tgt.my = 50; s.tgt.lift = 0;
            });
            kick();
        });

        document.addEventListener('pointermove', function (e) {
            if (e.pointerType === 'touch') return;
            // Don't tilt while a popup is open (its overlay sits above the header).
            if (document.getElementById('gdc-topup-overlay')) return;
            var el = e.target.closest && e.target.closest(SEL);
            if (!el) return;
            var r = el.getBoundingClientRect();
            var px = (e.clientX - r.left) / r.width;
            var py = (e.clientY - r.top) / r.height;
            px = px < 0 ? 0 : px > 1 ? 1 : px;
            py = py < 0 ? 0 : py > 1 ? 1 : py;
            var s = stateFor(el);
            s.tgt.ry   = (px - 0.5) * 2 * MAX;
            s.tgt.rx   = -(py - 0.5) * 2 * MAX;
            s.tgt.mx   = px * 100;
            s.tgt.my   = py * 100;
            s.tgt.lift = 1;
            if (!el.classList.contains('gdc-kbx--live')) el.classList.add('gdc-kbx--live');
            kick();
        }, { passive: true });

        document.addEventListener('pointerout', function (e) {
            var el = e.target.closest && e.target.closest(SEL);
            if (!el) return;
            // moving between children of the same box — stay live
            if (e.relatedTarget && el.contains(e.relatedTarget)) return;
            var s = active.get(el);
            if (!s) return;
            s.tgt.rx = 0; s.tgt.ry = 0;     // ease the tilt flat
            s.tgt.mx = 50; s.tgt.my = 50;   // drift the glow back to centre
            s.tgt.lift = 0;                 // fade glow + sheen out
            kick();
        }, { passive: true });
    }());
    </script>
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

/* ── Cover photo background (glassmorphic) ───────────────────────────────
   ::before = the actual Youzify cover image, kept vivid (the glass frosting
              is done by the ::after backdrop-filter, not by pre-blurring this)
   ::after  = the frosted-glass sheet: a translucent tint + backdrop blur that
              turns the live cover image into glassmorphism behind the content
   ─────────────────────────────────────────────────────────────────────── */
.gdc-profile-uplink::before {
    content: "";
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
    /* Sticky/parallax: the cover stays fixed to the viewport as the header
       scrolls, rather than stretching over the full content height. The
       section overflow:hidden clips it to the header bounds. (No transform
       here — a transform on the element would break fixed attachment.) */
    background-attachment: fixed;
    filter: brightness(0.98) saturate(1.35) contrast(1.04);
    transform: none;
    z-index: -2;
    pointer-events: none;
    /* Cinematic backdrop boot: focus-pull rising out of black (opacity + blur
       only, so fixed attachment is never disturbed). */
    animation: gdcCoverIn 3s cubic-bezier(0.22,1,0.36,1) both;
}
@keyframes gdcCoverIn {
    from { opacity: 0; filter: blur(26px) brightness(0)    saturate(1.35) contrast(1.04); }
    60%  { opacity: 1; }
    to   { opacity: 1; filter: blur(0)    brightness(0.98) saturate(1.35) contrast(1.04); }
}
/* background-attachment: fixed is unreliable on mobile — fall back to scroll */
@media (max-width: 768px) {
    .gdc-profile-uplink::before { background-attachment: scroll; }
}

/* ══ CINEMATIC HEADER ENTRANCE ════════════════════════════════════════════
   One choreographed "power-on": the whole section fades up while the cover
   zooms into focus, a light bar sweeps across, and a glow blooms from the top
   before the cards cascade in (see the per-element keyframes further down).
   ═══════════════════════════════════════════════════════════════════════ */
.gdc-profile-uplink {
    animation: gdcUplinkIn 1s ease both;
}
@keyframes gdcUplinkIn { from { opacity: 0; } to { opacity: 1; } }

/* Boot FX layer — sits above content; pure light, no pointer capture */
.gdc-boot-fx {
    position: absolute;
    inset: 0;
    z-index: 2;
    pointer-events: none;
    overflow: hidden;
    mix-blend-mode: screen;
}
/* The sweeping light bar */
.gdc-boot-fx::before {
    content: "";
    position: absolute;
    top: -20%;
    bottom: -20%;
    width: 45%;
    left: -60%;
    background: linear-gradient(
        100deg,
        transparent 0%,
        rgba(137,194,224,0.00) 18%,
        rgba(255,255,255,0.14) 50%,
        rgba(182,8,201,0.10) 70%,
        transparent 100%
    );
    filter: blur(8px);
    transform: skewX(-12deg);
    animation: gdcSweep 1.6s cubic-bezier(0.5,0,0.2,1) 0.25s both;
}
@keyframes gdcSweep {
    0%   { left: -60%; opacity: 0; }
    18%  { opacity: 1; }
    100% { left: 125%; opacity: 0; }
}
/* The top bloom that swells then settles */
.gdc-boot-fx::after {
    content: "";
    position: absolute;
    inset: 0;
    background: radial-gradient(130% 80% at 50% 0%,
                rgba(137,194,224,0.20), transparent 60%);
    opacity: 0;
    animation: gdcBloom 2.4s ease-out 0.1s both;
}
@keyframes gdcBloom { 0% { opacity: 0; } 28% { opacity: 1; } 100% { opacity: 0; } }

/* Respect reduced-motion: skip the whole boot sequence, show everything settled */
@media (prefers-reduced-motion: reduce) {
    .gdc-profile-uplink,
    .gdc-profile-uplink::before,
    .gdc-profile-uplink::after,
    .gdc-identity-wrap,
    .gdc-metrics-port .gdc-kbx,
    .gdc-balance-grid .gdc-kbx {
        animation: none !important;
        opacity: 1 !important;
        transform: none !important;
        filter: none !important;
    }
    .gdc-profile-uplink::before { filter: brightness(0.98) saturate(1.35) contrast(1.04) !important; transform: none !important; background-size: cover !important; }
    .gdc-boot-fx { display: none !important; }
}
.gdc-profile-uplink::after {
    content: "";
    position: absolute;
    inset: 0;
    /* Translucent tint — light enough to read the cover through the glass,
       deepening toward the bottom where the content sits, for legibility. */
    background: linear-gradient(
        165deg,
        rgba(18,24,38,0.10) 0%,
        rgba(11,14,20,0.26) 52%,
        rgba(8,11,17,0.52) 100%
    );
    /* The glassmorphism: frost the live cover image behind this sheet. */
    -webkit-backdrop-filter: blur(9px) saturate(1.45);
            backdrop-filter: blur(9px) saturate(1.45);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.06); /* crisp glass top edge */
    z-index: -1;
    pointer-events: none;
    /* Cinematic beat: the cover is glimpsed sharp, then the frost rolls in. */
    animation: gdcGlassIn 1.9s cubic-bezier(0.16,1,0.3,1) 0.35s both;
}
@keyframes gdcGlassIn {
    from {
        opacity: 0;
        -webkit-backdrop-filter: blur(0) saturate(1);
                backdrop-filter: blur(0) saturate(1);
    }
    to {
        opacity: 1;
        -webkit-backdrop-filter: blur(9px) saturate(1.45);
                backdrop-filter: blur(9px) saturate(1.45);
    }
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

/* ══ 3D POINTER-FOLLOW HOVER ═══════════════════════════════════════════════
   On hover the spinning conic rim hands off to a parallax 3D tilt that
   eases toward the cursor (damped per-frame in JS, never snapping), plus a
   pointer-anchored rim glow and a glass sheen that drifts across the surface.
   JS drives transform / box-shadow inline and these custom props:
     --kbx-mx / --kbx-my  glow + sheen origin (%)
     --kbx-lift           0..1 hover intensity (eases in on enter, out on exit)
   ═══════════════════════════════════════════════════════════════════════ */
.gdc-kbx {
    transform-style: preserve-3d;
    will-change: transform;
    backface-visibility: hidden;
}
.gdc-kbx.gdc-kbx--live { z-index: 4; }
/* Hand the spinning conic track over to a soft pointer-anchored rim glow.
   opacity is tied to --kbx-lift so the glow eases in/out with the tilt. */
.gdc-kbx.gdc-kbx--live::before {
    animation: none;
    inset: 0;
    opacity: var(--kbx-lift, 1);
    background: radial-gradient(
        300px circle at var(--kbx-mx, 50%) var(--kbx-my, 50%),
        var(--kbx-color, var(--gph-blue)) 0%,
        color-mix(in srgb, var(--kbx-color, var(--gph-blue)) 45%, transparent) 26%,
        transparent 62%
    );
}
/* Glass sheen — a soft specular highlight on the card surface that the
   pointer drags around, sold as light catching glass. Injected by JS. */
.gdc-kbx-glare {
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    z-index: 3;
    opacity: 0;
    background: radial-gradient(
        420px circle at var(--kbx-mx, 50%) var(--kbx-my, 50%),
        rgba(255,255,255,0.18) 0%,
        rgba(255,255,255,0.06) 20%,
        transparent 48%
    );
    mix-blend-mode: screen;
}
@media (prefers-reduced-motion: reduce) {
    .gdc-kbx.gdc-kbx--live { transform: none !important; box-shadow: none !important; }
    .gdc-kbx.gdc-kbx--live::before { animation: gdcBorderScan 4s linear infinite; opacity: 1; }
    .gdc-kbx-glare { display: none; }
}

/* ══ 1. IDENTITY PORT ════════════════════════════════════════════════════
   3D entrance: swings in from the left.
   ═══════════════════════════════════════════════════════════════════════ */
.gdc-identity-wrap {
    opacity: 0;
    transform: perspective(1600px) rotateY(-24deg) translateX(-70px) translateZ(-160px);
    filter: blur(12px);
    animation: gdcPortEnter 1.4s cubic-bezier(0.16,1,0.3,1) forwards;
}
@keyframes gdcPortEnter {
    to {
        opacity: 1;
        transform: perspective(1600px) rotateY(0) translateX(0) translateZ(0);
        filter: blur(0);
    }
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
    justify-content: center; /* centre the metrics block against the taller identity column */
    gap: 25px;
}
/* Entrance animation applied to the kinetic boxes in the metrics column —
   each card lifts out of depth and pulls into focus (parent perspective does
   not reach grandchildren, so the perspective() lives in the transform). */
.gdc-metrics-port .gdc-kbx,
.gdc-balance-grid .gdc-kbx {
    opacity: 0;
    transform: perspective(1600px) translateY(64px) translateZ(-150px) rotateX(14deg);
    filter: blur(9px);
    animation: gdcKbxEnter 1.15s cubic-bezier(0.16,1,0.3,1) forwards;
}
@keyframes gdcKbxEnter {
    to { opacity: 1; transform: none; filter: blur(0); }
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
    flex-wrap: wrap;                            /* wrap before scrolling */
    justify-content: space-between;             /* distribute across the row */
    align-items: center;
    column-gap: clamp(6px, 1vw, 18px);
    row-gap: 0;
    padding: 0 20px;
    overflow-x: hidden;                         /* never produce a scrollbar */
    scrollbar-width: none;
}
.gdc-profile-nav-inner::-webkit-scrollbar { display: none; }
.gdc-nav-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 22px 0;
    color: #64748b;
    text-decoration: none !important;
    font-weight: 900;
    font-size: clamp(0.62rem, 0.72vw, 0.75rem);
    letter-spacing: 1.4px;
    text-transform: uppercase;
    white-space: nowrap;
    border-bottom: 2px solid transparent;
    transition: color 0.25s, border-color 0.25s, transform 0.3s;
    opacity: 0;
    transform: translateY(14px);
    animation: gdcNavItemEnter 0.55s cubic-bezier(0.22,1,0.36,1) forwards;
    animation-delay: calc(1.35s + var(--gdc-nav-i, 0) * 0.07s);
}
@keyframes gdcNavItemEnter {
    to { opacity: 1; transform: none; }
}
.gdc-nav-item:hover {
    color: rgba(255,255,255,0.85);
}
.gdc-nav-item--active {
    color: var(--gph-magenta);
    border-bottom-color: var(--gph-magenta);
}

/* ══ Nav item icon — futuristic dashboard chip ════════════════════════════
   Each icon sits in a glass plate with a thin border and powers on a beat
   after its parent <a> appears (double-stagger). The plate color-shifts
   on hover and lights up magenta with a pulsing beacon when active.
   ═══════════════════════════════════════════════════════════════════════ */
.gdc-nav-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: rgba(255,255,255,0.025);
    border: 1px solid rgba(255,255,255,0.09);
    color: #64748b;
    flex-shrink: 0;
    transition:
        color 0.3s ease,
        background 0.3s ease,
        border-color 0.3s ease,
        transform 0.3s cubic-bezier(0.22,1,0.36,1),
        box-shadow 0.3s ease;
    opacity: 0;
    transform: scale(0.45) rotate(-12deg);
    animation: gdcNavIconPower 0.55s cubic-bezier(0.34,1.56,0.64,1) forwards;
    animation-delay: calc(1.55s + var(--gdc-nav-i, 0) * 0.07s);
}
.gdc-nav-icon svg {
    width: 14px;
    height: 14px;
    display: block;
    filter: drop-shadow(0 0 4px currentColor);
    opacity: 0.92;
}
@keyframes gdcNavIconPower {
    to { opacity: 1; transform: none; }
}

/* Scan-line sweep — fires once during the icon power-on */
.gdc-nav-icon::before {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: linear-gradient(115deg,
        transparent 0%,
        transparent 35%,
        rgba(137,194,224,0.35) 50%,
        transparent 65%,
        transparent 100%);
    background-size: 220% 100%;
    background-position: 220% 0;
    pointer-events: none;
    opacity: 0;
    animation: gdcNavIconScan 0.9s ease-out forwards;
    animation-delay: calc(1.75s + var(--gdc-nav-i, 0) * 0.07s);
}
@keyframes gdcNavIconScan {
    0%   { opacity: 0;   background-position:  220% 0; }
    20%  { opacity: 0.9; }
    100% { opacity: 0;   background-position: -120% 0; }
}

/* Hover — icon lifts and switches to the blue terminal color */
.gdc-nav-item:hover .gdc-nav-icon {
    color: var(--gph-blue);
    border-color: rgba(137,194,224,0.45);
    background: rgba(137,194,224,0.08);
    transform: translateY(-2px);
    box-shadow:
        0 0 14px rgba(137,194,224,0.25),
        inset 0 0 6px rgba(137,194,224,0.15);
}

/* Active — magenta glow with a live beacon dot */
.gdc-nav-item--active .gdc-nav-icon {
    color: var(--gph-magenta);
    background: rgba(182,8,201,0.12);
    border-color: rgba(182,8,201,0.45);
    box-shadow:
        0 0 18px rgba(182,8,201,0.35),
        inset 0 0 8px rgba(182,8,201,0.22);
}
.gdc-nav-item--active .gdc-nav-icon::after {
    content: "";
    position: absolute;
    top: 3px;
    right: 3px;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: var(--gph-magenta);
    box-shadow: 0 0 6px var(--gph-magenta);
    animation: gdcNavBeacon 1.6s ease-in-out infinite;
}
@keyframes gdcNavBeacon {
    0%, 100% { opacity: 0.35; transform: scale(0.8); }
    50%      { opacity: 1;    transform: scale(1.15); }
}

/* Honor reduced-motion preference */
@media (prefers-reduced-motion: reduce) {
    .gdc-nav-item,
    .gdc-nav-icon,
    .gdc-nav-icon::before,
    .gdc-nav-item--active .gdc-nav-icon::after {
        animation: none !important;
        opacity: 1 !important;
        transform: none !important;
    }
}

/* ══ Mobile — switch nav to a 4-col chip grid ════════════════════════════
   Each item becomes a vertical glass card with the icon on top and the
   label below. Active items glow magenta. Count badges become corner
   bubbles. Touch targets are sized for thumbs (≥36px icon, ≥74px chip).
   ═══════════════════════════════════════════════════════════════════════ */
@media (max-width: 720px) {
    /* Tighten the surrounding header on phones */
    .gdc-profile-uplink     { padding: 40px 12px 0; }
    .gdc-profile-hub        { gap: 22px; }
    .gdc-identity-inner     { padding: 28px 16px; }
    .gdc-avatar             { width: 110px; height: 110px; }
    .gdc-identity-name      { font-size: 1.3rem; }
    .gdc-balance-grid       { grid-template-columns: 1fr 1fr; gap: 12px; }
    .gdc-node-content       { padding: 20px 14px; }
    .gdc-node-value         { font-size: 1.3rem; }

    /* Nav wrapper — sits as a dashboard tray below the cards */
    .gdc-profile-nav {
        margin-top: 28px;
        background: rgba(0,0,0,0.45);
        padding: 14px 12px 18px;
        border-top: 1px solid var(--gph-border);
        border-radius: 16px 16px 0 0;
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
    }
    .gdc-profile-nav-inner {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        column-gap: 8px;
        row-gap: 10px;
        padding: 0;
        max-width: none;
        flex-wrap: initial;
        justify-content: initial;
    }

    /* Each item is a tappable chip */
    .gdc-nav-item {
        position: relative;
        flex-direction: column;
        align-items: center;
        justify-content: flex-start;
        gap: 7px;
        padding: 11px 4px;
        min-height: 78px;
        background: rgba(255,255,255,0.025);
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 12px;
        border-bottom-width: 1px;            /* override desktop bottom-border accent */
        font-size: 0.6rem;
        letter-spacing: 0.6px;
        line-height: 1.15;
        text-align: center;
        color: #94a3b8;
        transition:
            background 0.25s ease,
            border-color 0.25s ease,
            transform 0.15s ease,
            box-shadow 0.25s ease;
    }
    .gdc-nav-item:active {
        transform: scale(0.96);
    }
    .gdc-nav-item--active {
        background: rgba(182,8,201,0.1);
        border-color: rgba(182,8,201,0.45);
        box-shadow:
            0 0 16px rgba(182,8,201,0.20),
            inset 0 0 12px rgba(182,8,201,0.08);
        color: #fff;
    }

    /* Larger, thumb-friendly icon plate */
    .gdc-nav-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
    }
    .gdc-nav-icon svg {
        width: 17px;
        height: 17px;
    }
    /* The active beacon dot needs to sit on the larger plate */
    .gdc-nav-item--active .gdc-nav-icon::after {
        top: 4px;
        right: 4px;
    }

    /* Label wraps to a max of 2 lines */
    .gdc-nav-text {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        white-space: normal;
        word-break: break-word;
        max-width: 100%;
    }

    /* Count badge becomes a corner bubble */
    .gdc-nav-item .count {
        position: absolute;
        top: 6px;
        right: 6px;
        margin-left: 0;
        padding: 1px 5px;
        min-width: 16px;
        background: rgba(182,8,201,0.85);
        border-color: rgba(182,8,201,0.6);
        color: #fff;
        font-size: 0.52rem;
        line-height: 1.45;
        z-index: 2;
        box-shadow: 0 0 8px rgba(182,8,201,0.4);
    }
    .gdc-nav-item--active .count {
        background: var(--gph-magenta);
        color: #fff;
    }
}

/* Very narrow phones — drop to 3 columns to keep labels readable */
@media (max-width: 380px) {
    .gdc-profile-nav-inner {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .gdc-nav-item {
        min-height: 82px;
        font-size: 0.62rem;
    }
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
    display: inline-flex !important;
    align-items: center;
    gap: 9px;
}
.youzify.youzify-profile .item-body nav.bp-navs ul li.current a,
.youzify.youzify-profile .youzify-tabs-nav a.selected {
    color: #b608c9 !important;
    border-bottom-color: #b608c9 !important;
}

/* ══ Sub-nav icon chips — matched to primary-nav futuristic style ════════
   JS injects <span class="gdc-subnav-icon"><svg/></span> as the first child
   of each BP/Youzify subnav link. Each chip is smaller (22px vs 26px) so
   it lives comfortably inside the existing subnav row, and powers on with
   its own per-container stagger via --gdc-subnav-i.
   ═══════════════════════════════════════════════════════════════════════ */
.gdc-subnav-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 6px;
    background: rgba(255,255,255,0.025);
    border: 1px solid rgba(255,255,255,0.09);
    color: currentColor;
    flex-shrink: 0;
    transition:
        color 0.25s ease,
        background 0.25s ease,
        border-color 0.25s ease,
        transform 0.25s cubic-bezier(0.22,1,0.36,1),
        box-shadow 0.25s ease;
    opacity: 0;
    transform: scale(0.45) rotate(-10deg);
    animation: gdcSubnavPower 0.45s cubic-bezier(0.34,1.56,0.64,1) forwards;
    animation-delay: calc(0.12s + var(--gdc-subnav-i, 0) * 0.06s);
}
.gdc-subnav-icon svg {
    width: 12px;
    height: 12px;
    display: block;
    filter: drop-shadow(0 0 3px currentColor);
    opacity: 0.92;
}
@keyframes gdcSubnavPower {
    to { opacity: 1; transform: none; }
}

/* Scan-line sweep — fires once during the icon power-on */
.gdc-subnav-icon::before {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: linear-gradient(115deg,
        transparent 0%,
        transparent 35%,
        rgba(137,194,224,0.35) 50%,
        transparent 65%,
        transparent 100%);
    background-size: 220% 100%;
    background-position: 220% 0;
    pointer-events: none;
    opacity: 0;
    animation: gdcSubnavScan 0.8s ease-out forwards;
    animation-delay: calc(0.28s + var(--gdc-subnav-i, 0) * 0.06s);
}
@keyframes gdcSubnavScan {
    0%   { opacity: 0;   background-position:  220% 0; }
    20%  { opacity: 0.85; }
    100% { opacity: 0;   background-position: -120% 0; }
}

/* Hover — blue terminal lift */
.item-list-tabs li a:hover .gdc-subnav-icon,
nav.bp-navs li a:hover .gdc-subnav-icon,
.youzify-tabs-nav a:hover .gdc-subnav-icon {
    color: #89C2E0;
    border-color: rgba(137,194,224,0.45);
    background: rgba(137,194,224,0.08);
    transform: translateY(-1px);
    box-shadow:
        0 0 12px rgba(137,194,224,0.22),
        inset 0 0 5px rgba(137,194,224,0.12);
}

/* Active / current — magenta with beacon dot */
.item-list-tabs li.current .gdc-subnav-icon,
.item-list-tabs li.selected .gdc-subnav-icon,
nav.bp-navs li.current .gdc-subnav-icon,
nav.bp-navs li.selected .gdc-subnav-icon,
.youzify-tabs-nav a.selected .gdc-subnav-icon,
.youzify-tabs-nav a.current .gdc-subnav-icon {
    color: #b608c9;
    background: rgba(182,8,201,0.12);
    border-color: rgba(182,8,201,0.45);
    box-shadow:
        0 0 14px rgba(182,8,201,0.32),
        inset 0 0 6px rgba(182,8,201,0.20);
}
.item-list-tabs li.current .gdc-subnav-icon::after,
.item-list-tabs li.selected .gdc-subnav-icon::after,
nav.bp-navs li.current .gdc-subnav-icon::after,
nav.bp-navs li.selected .gdc-subnav-icon::after,
.youzify-tabs-nav a.selected .gdc-subnav-icon::after,
.youzify-tabs-nav a.current .gdc-subnav-icon::after {
    content: "";
    position: absolute;
    top: 2px;
    right: 2px;
    width: 3px;
    height: 3px;
    border-radius: 50%;
    background: #b608c9;
    box-shadow: 0 0 5px #b608c9;
    animation: gdcSubnavBeacon 1.6s ease-in-out infinite;
}
@keyframes gdcSubnavBeacon {
    0%, 100% { opacity: 0.35; transform: scale(0.8); }
    50%      { opacity: 1;    transform: scale(1.15); }
}

/* Reduced-motion guard */
@media (prefers-reduced-motion: reduce) {
    .gdc-subnav-icon,
    .gdc-subnav-icon::before,
    .gdc-subnav-icon::after {
        animation: none !important;
        opacity: 1 !important;
        transform: none !important;
    }
}

/* Mobile — slightly larger chip for thumb taps */
@media (max-width: 720px) {
    .gdc-subnav-icon { width: 26px; height: 26px; border-radius: 8px; }
    .gdc-subnav-icon svg { width: 14px; height: 14px; }
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

/* ══════════════════════════════════════════════════════════════════════════
   TOP-UP — card buttons + futuristic "builds itself" popups
   ══════════════════════════════════════════════════════════════════════════ */

/* Visually-hidden launch hosts (task-checkout trigger + POST forms). Kept in
   the DOM + programmatically clickable; never shown. */
.gdc-topup-hidden-host {
    position: absolute !important;
    width: 1px; height: 1px;
    margin: -1px; padding: 0; border: 0;
    overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%);
    white-space: nowrap;
}

/* ── Card "Top Up" button — powers on a beat after its card ─────────────── */
.gdc-topup-btn {
    margin-top: 14px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 15px 7px 11px;
    border-radius: 999px;
    border: 1px solid var(--gph-accent, var(--gph-blue));
    background: rgba(255,255,255,0.04);
    color: var(--gph-accent, var(--gph-blue));
    cursor: pointer;
    font-family: "Inter", sans-serif;
    font-size: 0.62rem;
    font-weight: 900;
    line-height: 1;
    letter-spacing: 1.3px;
    text-transform: uppercase;
    transition: transform 0.35s cubic-bezier(0.34,1.56,0.64,1),
                padding 0.35s cubic-bezier(0.34,1.56,0.64,1),
                font-size 0.3s cubic-bezier(0.22,1,0.36,1),
                letter-spacing 0.3s ease,
                gap 0.3s ease,
                box-shadow 0.3s ease, background 0.25s ease, color 0.2s ease;
    opacity: 0;
    transform: translateY(10px) scale(0.94);
    animation: gdcTpBtnIn 0.55s cubic-bezier(0.34,1.56,0.64,1) forwards;
}
@keyframes gdcTpBtnIn { to { opacity: 1; transform: none; } }
/* Stagger each button to land just after its card has slid in. */
.gdc-stagger-2 .gdc-topup-btn { animation-delay: 1.00s; }
.gdc-stagger-3 .gdc-topup-btn { animation-delay: 1.15s; }
.gdc-stagger-4 .gdc-topup-btn { animation-delay: 1.30s; }
/* Hover: grow the real type + padding (crisp, not a blurry scale) for a
   bigger, easier-to-read pill that lifts and glows on its accent colour. */
.gdc-topup-btn:hover,
.gdc-topup-btn:focus-visible {
    background: var(--gph-accent, var(--gph-blue));
    color: #06080d;
    transform: translateY(-3px);
    padding: 10px 21px 10px 16px;
    gap: 8px;
    font-size: 0.76rem;
    letter-spacing: 1.7px;
    box-shadow: 0 16px 34px -10px var(--gph-accent, var(--gph-blue)),
                0 0 24px -4px var(--gph-accent, var(--gph-blue));
    outline: none;
}
.gdc-topup-btn:active { transform: translateY(-1px) scale(0.97); }
.gdc-topup-btn__plus { display: inline-flex; }
.gdc-topup-btn__plus svg {
    width: 12px; height: 12px; display: block;
    transition: transform 0.4s cubic-bezier(0.34,1.56,0.64,1);
}
.gdc-topup-btn:hover .gdc-topup-btn__plus svg,
.gdc-topup-btn:focus-visible .gdc-topup-btn__plus svg {
    transform: rotate(90deg) scale(1.18);
}

/* ── Overlay ─────────────────────────────────────────────────────────────── */
.gdc-tp-overlay {
    /* The popup is portaled to <body>, OUTSIDE .gdc-profile-uplink, so the brand
       tokens must be (re)declared here or var(--gph-accent) resolves to nothing
       and the CTA loses its fill. */
    --gph-magenta: #b608c9;
    --gph-blue:    #89C2E0;
    --gph-green:   #00ff88;
    position: fixed;
    inset: 0;
    z-index: 2147483646;            /* above the floating AI chat bubble */
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(2,6,14,0.78);
    -webkit-backdrop-filter: blur(8px);
    backdrop-filter: blur(8px);
    opacity: 0;
    transition: opacity 0.22s ease;
    font-family: "Inter", sans-serif;
}
.gdc-tp-overlay.is-open { opacity: 1; }
.gdc-tp-overlay.is-closing { opacity: 0; }
/* While a popup is open, lock the page so only the dialog scrolls (and the
   header behind it cannot add stray scrollbars). */
html.gdc-tp-lock { overflow: hidden !important; }

/* ── Dialog — slides + scales in; children stagger after it ─────────────── */
.gdc-tp-dialog {
    --gph-accent: #89C2E0;
    position: relative;
    width: min(460px, 100%);
    max-height: 92vh;
    overflow-x: hidden;
    overflow-y: auto;
    padding: 30px 28px 24px;
    border-radius: 24px;
    border: 1px solid rgba(255,255,255,0.08);
    background: linear-gradient(165deg, #0d1320 0%, #080b12 100%);
    /* Accent-tinted bloom + inner glow ties each popup to its card colour */
    box-shadow: 0 40px 120px rgba(0,0,0,0.6),
                0 0 0 1px rgba(255,255,255,0.03),
                inset 0 1px 0 rgba(255,255,255,0.04),
                inset 0 0 60px -30px var(--gph-accent),
                0 30px 90px -45px var(--gph-accent);
    color: #e2e8f0;
    opacity: 0;
    transform: translateY(28px) scale(0.94);
    filter: blur(6px);
    transition: transform 0.5s cubic-bezier(0.16,1,0.3,1),
                opacity 0.45s ease,
                filter 0.5s ease;
}
.gdc-tp-overlay.is-open .gdc-tp-dialog { opacity: 1; transform: none; filter: blur(0); }
/* Rotating neon kinetic rim — the header card border, on the popup frame.
   Uses the border-mask trick (works with the scrollable dialog) and an
   animated @property angle so the accent arc sweeps the rounded rectangle. */
@property --gdc-tp-angle { syntax: "<angle>"; inherits: false; initial-value: 0deg; }
.gdc-tp-dialog::before {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: 24px;
    padding: 1.5px;
    background: conic-gradient(
        from var(--gdc-tp-angle, 0deg),
        transparent 0%,
        transparent 35%,
        var(--gph-accent) 50%,
        transparent 65%,
        transparent 100%
    );
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
            mask-composite: exclude;
    animation: gdcTpRimSpin 5s linear infinite;
    pointer-events: none;
    z-index: 1;
}
@keyframes gdcTpRimSpin { to { --gdc-tp-angle: 360deg; } }
/* Top edge scan-line that sweeps on open */
.gdc-tp-scan {
    position: absolute;
    left: 12px; right: 12px; top: 0;
    height: 2px;
    border-radius: 0 0 4px 4px;
    background: linear-gradient(90deg, transparent, var(--gph-accent), transparent);
    opacity: 0;
    transform: scaleX(0.2);
    transform-origin: center;
    animation: gdcTpScan 1.1s ease-out 0.15s forwards;
}
@keyframes gdcTpScan {
    0%   { opacity: 0; transform: scaleX(0.2); }
    35%  { opacity: 1; }
    100% { opacity: 0.55; transform: scaleX(1); }
}
.gdc-tp-close {
    position: absolute;
    top: 12px; right: 14px;
    width: 30px; height: 30px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.05);
    color: #94a3b8;
    font-size: 20px; line-height: 1;
    cursor: pointer;
    transition: background 0.2s, color 0.2s, transform 0.2s;
    z-index: 2;
}
.gdc-tp-close:hover { background: rgba(255,255,255,0.1); color: #fff; transform: rotate(90deg); }

/* Each stacked row reveals in sequence — the dialog "builds itself". */
.gdc-tp-row {
    opacity: 0;
    transform: translateY(14px);
    animation: gdcTpRowIn 0.5s cubic-bezier(0.22,1,0.36,1) forwards;
    animation-delay: calc(0.18s + var(--i, 0) * 0.08s);
}
@keyframes gdcTpRowIn { to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) {
    .gdc-tp-dialog { filter: none !important; transition: opacity 0.2s ease !important; transform: none !important; }
    .gdc-tp-dialog::before { animation: none !important; }
    .gdc-tp-scan, .gdc-tp-row { animation: none !important; opacity: 1 !important; transform: none !important; }
}

/* Head */
.gdc-tp-head {
    display: flex;
    gap: 14px;
    align-items: flex-start;
    padding-right: 30px;
}
.gdc-tp-head h2 { margin: 0; font-size: 1.15rem; font-weight: 900; color: #fff; }
.gdc-tp-tag { margin: 5px 0 0; font-size: 0.78rem; color: #94a3b8; line-height: 1.4; }
.gdc-tp-tag strong { color: var(--gph-accent); }
.gdc-tp-badge {
    flex-shrink: 0;
    width: 46px; height: 46px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 14px;
    border: 1px solid var(--gph-accent);
    background: rgba(255,255,255,0.04);
    color: var(--gph-accent);
    font-size: 22px;
    box-shadow: inset 0 0 16px -6px var(--gph-accent), 0 0 18px -8px var(--gph-accent);
}
.gdc-tp-badge svg { width: 24px; height: 24px; }

/* Current-balance strip */
.gdc-tp-balance {
    margin-top: 16px;
    font-family: monospace;
    font-size: 0.7rem;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #64748b;
}
.gdc-tp-balance strong { color: #e2e8f0; font-size: 0.92rem; }

/* Benefit list (DGEN sales page) */
.gdc-tp-benefits { margin-top: 16px; display: flex; flex-direction: column; gap: 10px; }
.gdc-tp-benefit {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 11px 13px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.06);
    background: rgba(255,255,255,0.025);
}
.gdc-tp-benefit svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; color: var(--gph-accent); }
.gdc-tp-benefit span { display: flex; flex-direction: column; font-size: 0.74rem; color: #94a3b8; line-height: 1.45; }
.gdc-tp-benefit strong { margin-bottom: 2px; color: #fff; font-size: 0.8rem; font-weight: 800; }

/* Amount selector */
.gdc-tp-label {
    margin: 18px 0 10px;
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: #cbd5e1;
}
.gdc-tp-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.gdc-tp-chip {
    flex: 1 1 auto;
    min-width: 68px;
    padding: 11px 10px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.1);
    background: rgba(255,255,255,0.03);
    color: #e2e8f0;
    font-size: 0.85rem;
    font-weight: 800;
    cursor: pointer;
    transition: border-color 0.18s, background 0.18s, transform 0.15s, box-shadow 0.2s;
}
.gdc-tp-chip small {
    display: block;
    margin-top: 3px;
    font-size: 0.55rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #64748b;
}
.gdc-tp-chip:hover { border-color: var(--gph-accent); transform: translateY(-1px); }
.gdc-tp-chip.is-active {
    border-color: var(--gph-accent);
    background: rgba(255,255,255,0.06);
    color: #fff;
    box-shadow: inset 0 0 0 1px var(--gph-accent), 0 6px 18px -8px var(--gph-accent);
}
.gdc-tp-chip.is-active small { color: var(--gph-accent); }
.gdc-tp-custom {
    margin-top: 10px;
    width: 100%;
    box-sizing: border-box;
    padding: 11px 14px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.1) !important;
    background: rgba(255,255,255,0.03) !important;
    color: #fff !important;
    font-size: 0.9rem;
    font-weight: 700;
}
.gdc-tp-custom:focus { outline: none; border-color: var(--gph-accent) !important; }

/* Total strip */
.gdc-tp-total {
    margin-top: 16px;
    padding: 13px 16px;
    display: flex;
    align-items: baseline;
    gap: 8px;
    border-radius: 12px;
    border: 1px dashed rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.03);
    font-family: monospace;
    font-size: 0.72rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #64748b;
}
.gdc-tp-total strong { font-family: "Inter", sans-serif; font-size: 1.5rem; letter-spacing: 0; color: #fff; }
.gdc-tp-total small { margin-left: auto; font-size: 0.66rem; text-transform: none; color: #64748b; }

/* Primary CTA — glossy, glowing, unmistakably a button */
.gdc-tp-cta {
    margin-top: 20px;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 17px 22px;
    border: 0;
    border-radius: 14px;
    background:
        linear-gradient(180deg, rgba(255,255,255,0.22), rgba(255,255,255,0) 46%),
        var(--gph-accent, var(--gph-blue));
    color: #06080d;
    font-family: "Inter", sans-serif;
    font-size: 0.86rem;
    font-weight: 900;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    cursor: pointer;
    box-shadow:
        0 14px 34px -10px var(--gph-accent, var(--gph-blue)),
        0 0 0 1px rgba(255,255,255,0.08) inset,
        inset 0 1px 0 rgba(255,255,255,0.4);
    transition: transform 0.18s cubic-bezier(0.22,1,0.36,1), box-shadow 0.25s, filter 0.2s;
}
.gdc-tp-cta svg { width: 19px; height: 19px; transition: transform 0.25s; }
.gdc-tp-cta:hover {
    transform: translateY(-2px);
    filter: brightness(1.07) saturate(1.05);
    box-shadow:
        0 20px 46px -12px var(--gph-accent, var(--gph-blue)),
        inset 0 1px 0 rgba(255,255,255,0.5);
}
.gdc-tp-cta:hover svg { transform: translateX(5px); }
.gdc-tp-cta:active { transform: translateY(0) scale(0.99); }

.gdc-tp-foot {
    margin-top: 14px;
    text-align: center;
    font-size: 0.66rem;
    letter-spacing: 0.3px;
    color: #475569;
}
.gdc-tp-alt {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid rgba(255,255,255,0.07);
    text-align: center;
    font-size: 0.74rem;
    color: #64748b;
}
.gdc-tp-alt a {
    color: var(--gph-accent);
    font-weight: 800;
    text-decoration: none;
    white-space: nowrap;
}
.gdc-tp-alt a:hover { text-decoration: underline; }

/* ── Embedded secure-checkout view (iframe inside the popup) ─────────────── */
.gdc-tp-dialog--checkout {
    width: min(1080px, 100%);
    height: min(90vh, 920px);
    padding: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.gdc-tp-cobar {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 15px 20px;
    font-size: 0.7rem;
    font-weight: 900;
    letter-spacing: 1.6px;
    text-transform: uppercase;
    color: #cbd5e1;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    background: rgba(255,255,255,0.02);
}
.gdc-tp-cobar__dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--gph-accent);
    box-shadow: 0 0 10px var(--gph-accent);
    animation: gdcNavBeacon 1.6s ease-in-out infinite;
}
.gdc-tp-frame {
    flex: 1 1 auto;
    width: 100%;
    border: 0;
    background: #0a0f1a;
    opacity: 0;
    transition: opacity 0.3s ease;
}
.gdc-tp-frame.is-loaded { opacity: 1; }
.gdc-tp-loading {
    position: absolute;
    inset: 52px 0 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    color: #64748b;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
}
.gdc-tp-spinner {
    width: 38px; height: 38px;
    border-radius: 50%;
    border: 3px solid rgba(255,255,255,0.1);
    border-top-color: var(--gph-accent);
    animation: gdcTpSpin 0.8s linear infinite;
}
@keyframes gdcTpSpin { to { transform: rotate(360deg); } }
.gdc-tp-dialog--checkout .gdc-tp-foot {
    flex: 0 0 auto;
    margin: 0;
    padding: 10px 20px 14px;
    border-top: 1px solid rgba(255,255,255,0.06);
}
.gdc-tp-dialog--checkout .gdc-tp-foot a { color: var(--gph-accent); font-weight: 700; text-decoration: none; }
.gdc-tp-dialog--checkout .gdc-tp-foot a:hover { text-decoration: underline; }

/* ── Spend / Shop popup — wide cinematic frame embedding /shop ──────────── */
.gdc-tp-dialog--shop {
    width: min(1180px, 96vw);
    height: min(92vh, 980px);
    padding: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.gdc-tp-frame--shop {
    flex: 1 1 auto;
    width: 100%;
    border: 0;
    background: #0a0f1a;
    opacity: 0;
    transition: opacity 0.5s ease;
}
.gdc-tp-frame--shop.is-loaded { opacity: 1; }
.gdc-tp-dialog--shop .gdc-tp-foot {
    flex: 0 0 auto;
    margin: 0;
    padding: 10px 20px 14px;
    border-top: 1px solid rgba(255,255,255,0.06);
}
.gdc-tp-dialog--shop .gdc-tp-foot a { color: var(--gph-accent); font-weight: 700; text-decoration: none; }
.gdc-tp-dialog--shop .gdc-tp-foot a:hover { text-decoration: underline; }
@media (max-width: 480px) {
    .gdc-tp-dialog--shop { padding: 0; height: 94vh; }
}

/* ── Embedded wallet card atop the DGEN popup ──────────────────────────────
   Widen the dialog so the wallet card breathes, neutralise the wallet-plugin
   outer .gend-wallet chrome (we only want the card), and reflow the card to a
   wrap layout so it never overflows the narrower popup width. */
.gdc-tp-dialog--wallet { width: min(720px, 96vw); }
.gdc-tp-walletcard {
    width: 100%;
    margin-bottom: 26px;
    padding-bottom: 26px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
.gdc-tp-walletcard .gend-wallet {
    background: none !important;
    border: 0 !important;
    box-shadow: none !important;
    padding: 0 !important;
    margin: 0 !important;
    max-width: none !important;
    width: 100% !important;
}
.gdc-tp-walletcard .gend-wallet__card-inner {
    display: flex !important;
    flex-wrap: wrap;
    align-items: center;
    gap: 14px 20px;
}
.gdc-tp-walletcard .gend-wallet__card-actions {
    flex: 1 1 100%;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 8px;
}
/* Embed shows ONLY the DGEN card — hide tabs, the overview hero, other panels
   and every non-transact balance card from the relocated full wallet. */
.gdc-tp-walletcard .gend-wallet__tabs,
.gdc-tp-walletcard .gw-hero,
.gdc-tp-walletcard .economy-control-section,
.gdc-tp-walletcard .gend-wallet__panel:not(#gwp-overview),
.gdc-tp-walletcard .gend-wallet__balance-card:not([data-point-type="transact"]) {
    display: none !important;
}
.gdc-tp-walletcard .gend-wallet__panel,
.gdc-tp-walletcard .gend-wallet__balances {
    padding: 0 !important;
    margin: 0 !important;
    background: none !important;
    border: 0 !important;
    display: block !important;
}
/* The wallet action modals append to <body>; lift them above the popup overlay. */
body.gw-modal-open .gw-modal-backdrop,
body.gw-modal-open .gw-modal {
    z-index: 2147483647 !important;
}

@media (max-width: 480px) {
    .gdc-tp-dialog { padding: 26px 20px 20px; border-radius: 20px; }
    .gdc-tp-dialog--checkout { padding: 0; height: 92vh; }
    .gdc-tp-head h2 { font-size: 1.05rem; }
}

/* Honor reduced-motion */
@media (prefers-reduced-motion: reduce) {
    .gdc-topup-btn,
    .gdc-tp-row,
    .gdc-tp-scan,
    .gdc-tp-dialog,
    .gdc-tp-overlay {
        animation: none !important;
        transition: none !important;
        opacity: 1 !important;
        transform: none !important;
    }
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
