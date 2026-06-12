<?php
/**
 * Web Shell — browser-resident counterpart to the GenD Desktop App.
 *
 * Reachable at `/web-shell/` on gend.me. Renders a custom full-window
 * SPA that mirrors the desktop app's surface:
 *
 *   - Sites        — the signed-in user's memberships (read-only mvp)
 *   - Terminal     — xterm.js wired to a per-user ephemeral container
 *                    PTY backend (Phase 22 — currently shows a
 *                    "container provisioning…" stub explaining the
 *                    contract until the backend ships)
 *   - Gas Station  — quick link to the user's groups' Compute Gas tabs
 *   - Davinci      — voice-control panel (re-uses Web Speech API so it
 *                    works in any Chromium browser without an extra
 *                    backend)
 *
 * Auth: leans on the existing gend.me OAuth/cookie session. If the
 * visitor isn't logged in, we redirect to /wp-login.php with a return
 * URL so they come back here after.
 *
 * Layout: bypasses the theme entirely (`template_redirect` with a
 * custom canvas). The shell needs the full viewport and the gend.me
 * site chrome would just steal pixels.
 *
 * The actual interactive UI is vanilla JS + a small style block —
 * no React/Vite to bake in here. Keeps the WP plugin lightweight and
 * the page loads in <100ms. Heavy lifting (terminal, voice, future
 * Claude integration) lives in self-contained modules pulled by URL.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'gs_web_shell_register' ) ) {

    function gs_web_shell_register() {
        add_rewrite_rule( '^web-shell/?$',           'index.php?gs_web_shell=1', 'top' );
        add_rewrite_rule( '^web-shell/([^/]+)/?$',   'index.php?gs_web_shell=1&gs_web_shell_tab=$matches[1]', 'top' );
        add_rewrite_tag( '%gs_web_shell%',     '([0-9]+)' );
        add_rewrite_tag( '%gs_web_shell_tab%', '([^/]+)' );
    }
    add_action( 'init', 'gs_web_shell_register' );

    // First-load helper: flush rewrites once when the plugin activates
    // so the new rule registers without the admin manually visiting
    // Settings → Permalinks. Cheap idempotent option-flag pattern.
    function gs_web_shell_flush_once() {
        if ( get_option( 'gs_web_shell_rewrite_flushed' ) === '1' ) return;
        gs_web_shell_register();
        flush_rewrite_rules( false );
        update_option( 'gs_web_shell_rewrite_flushed', '1', false );
    }
    add_action( 'init', 'gs_web_shell_flush_once', 20 );
}

if ( ! function_exists( 'gs_web_shell_render' ) ) {

    function gs_web_shell_render() {
        if ( (int) get_query_var( 'gs_web_shell' ) !== 1 ) return;

        // Require login. Return URL points back here so a fresh-tab
        // visitor lands on /web-shell/ after auth without losing the
        // deep link tab.
        if ( ! is_user_logged_in() ) {
            $return = home_url( $_SERVER['REQUEST_URI'] ?? '/web-shell/' );
            wp_safe_redirect( wp_login_url( $return ) );
            exit;
        }

        $tab = sanitize_key( (string) get_query_var( 'gs_web_shell_tab' ) );
        $allowed_tabs = array( 'sites', 'terminal', 'chat', 'gas-station', 'davinci' );
        if ( ! in_array( $tab, $allowed_tabs, true ) ) $tab = 'sites';

        $user      = wp_get_current_user();
        $rest_root = esc_url_raw( rest_url() );
        $nonce     = wp_create_nonce( 'wp_rest' );

        nocache_headers();
        // Render our own minimal document — no theme chrome.
        header( 'Content-Type: text/html; charset=utf-8' );
        ?><!doctype html>
<html lang="<?php echo esc_attr( get_locale() ); ?>" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>GenD Web Shell</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><circle cx='12' cy='12' r='10' fill='%23a78bfa'/></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.5.0/css/xterm.min.css">
    <style>
        :root {
            --bg: #0a0e1c;
            --bg-elev: rgba(15,23,42,0.65);
            --bg-elev-2: rgba(15,23,42,0.85);
            --border: rgba(255,255,255,0.08);
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --accent: #a78bfa;
            --accent-2: #22d3ee;
            --success: #34d399;
            --danger: #f87171;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: var(--bg); color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, sans-serif; height: 100%;
            -webkit-font-smoothing: antialiased; }
        body { display: flex; flex-direction: column; min-height: 100vh; overflow: hidden; }

        /* ─── Top chrome ─── */
        .ws-topbar { flex-shrink: 0; display: flex; align-items: center; gap: 16px;
            padding: 10px 18px; background: var(--bg-elev-2);
            border-bottom: 1px solid var(--border); }
        .ws-brand { display: flex; align-items: center; gap: 8px; font-weight: 800;
            font-size: 13px; letter-spacing: -0.2px; }
        .ws-brand-dot { width: 10px; height: 10px; border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            box-shadow: 0 0 12px rgba(167,139,250,0.55); }
        .ws-brand-name { color: #fff; }
        .ws-brand-sub  { color: var(--text-muted); font-weight: 600; font-size: 11px; }
        .ws-user { margin-left: auto; display: flex; align-items: center; gap: 10px;
            font-size: 12px; color: var(--text-muted); }
        .ws-user strong { color: #fff; font-weight: 700; }
        .ws-user a { color: var(--accent); text-decoration: none; }

        /* ─── Layout: side-nav + content ─── */
        .ws-layout { flex: 1; min-height: 0; display: grid;
            grid-template-columns: 220px 1fr; }
        .ws-sidenav { background: var(--bg-elev); border-right: 1px solid var(--border);
            padding: 14px 10px; display: flex; flex-direction: column; gap: 4px; }
        .ws-nav-item { display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border: 0; background: transparent; cursor: pointer;
            color: var(--text-muted); font-size: 13px; font-weight: 600;
            text-align: left; border-radius: 8px; font-family: inherit; }
        .ws-nav-item:hover { background: rgba(167,139,250,0.06); color: #fff; }
        .ws-nav-item.is-active { background: rgba(167,139,250,0.14);
            color: #fff; box-shadow: inset 2px 0 0 0 var(--accent); }
        .ws-nav-icon { width: 16px; height: 16px; flex-shrink: 0; }
        .ws-nav-spacer { flex: 1; }
        .ws-nav-foot { padding: 10px 12px; font-size: 10.5px; color: var(--text-muted);
            border-top: 1px solid var(--border); margin-top: 10px; }

        .ws-content { padding: 18px 22px; overflow: auto; min-width: 0; }
        .ws-panel { display: none; }
        .ws-panel.is-active { display: block; animation: wsFade 0.25s ease-out; }
        @keyframes wsFade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
        .ws-panel-head { display: flex; align-items: baseline; gap: 12px; margin-bottom: 16px; }
        .ws-panel-head h1 { margin: 0; font-size: 1.4rem; font-weight: 900; color: #fff; letter-spacing: -0.4px; }
        .ws-panel-head p  { margin: 0; font-size: 12.5px; color: var(--text-muted); }

        /* ─── Sites grid ─── */
        .ws-sites-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; }
        .ws-site { padding: 16px; background: var(--bg-elev); border: 1px solid var(--border); border-radius: 12px;
            display: flex; flex-direction: column; gap: 10px; transition: border-color 0.15s, transform 0.15s; }
        .ws-site:hover { border-color: rgba(167,139,250,0.45); transform: translateY(-2px); }
        .ws-site-head { display: flex; align-items: center; gap: 10px; }
        .ws-site-logo { width: 36px; height: 36px; border-radius: 8px; background: rgba(167,139,250,0.10);
            object-fit: cover; }
        .ws-site-title { font-size: 14px; font-weight: 800; color: #fff; line-height: 1.2; }
        .ws-site-host  { font-size: 11px; color: var(--text-muted); }
        .ws-site-meta { display: flex; flex-wrap: wrap; gap: 6px; font-size: 10.5px; color: var(--text-muted); }
        .ws-site-meta span { padding: 3px 8px; background: rgba(255,255,255,0.04); border-radius: 999px; }
        .ws-site-actions { display: flex; gap: 6px; margin-top: 4px; }
        .ws-btn { padding: 7px 11px; border-radius: 8px; font-size: 11.5px; font-weight: 700;
            text-decoration: none; cursor: pointer; transition: filter 0.15s;
            display: inline-flex; align-items: center; gap: 5px; border: 0; font-family: inherit; }
        .ws-btn--primary { background: linear-gradient(135deg, var(--accent), var(--accent-2)); color: #0a0e1c; }
        .ws-btn--ghost { background: rgba(255,255,255,0.04); color: var(--text); border: 1px solid var(--border); }
        .ws-btn:hover { filter: brightness(1.1); }
        .ws-loading { padding: 30px; text-align: center; color: var(--text-muted); font-size: 13px; }
        .ws-empty { padding: 30px; text-align: center; color: var(--text-muted); }

        /* ─── Terminal ─── */
        .ws-terminal-wrap { display: flex; flex-direction: column; height: calc(100vh - 110px);
            background: #000; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        .ws-terminal-head { display: flex; align-items: center; gap: 10px; padding: 8px 14px;
            background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border); font-size: 11.5px; }
        .ws-terminal-status { padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
            background: rgba(251,191,36,0.10); color: #fbbf24; border: 1px solid rgba(251,191,36,0.25); }
        .ws-terminal-status.is-connected { background: rgba(52,211,153,0.10); color: var(--success); border-color: rgba(52,211,153,0.25); }
        .ws-terminal-status.is-error { background: rgba(248,113,113,0.10); color: var(--danger); border-color: rgba(248,113,113,0.25); }
        .ws-gcloud-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px;
            font-size: 10.5px; font-weight: 700; letter-spacing: 0.02em;
            background: rgba(255,255,255,0.04); border: 1px solid var(--border); color: var(--text-muted); }
        .ws-gcloud-pill[data-state="connected"] { background: rgba(52,211,153,0.10); color: var(--success); border-color: rgba(52,211,153,0.25); }
        .ws-gcloud-pill[data-state="missing-config"] { background: rgba(251,191,36,0.10); color: #fbbf24; border-color: rgba(251,191,36,0.25); }
        .ws-gcloud-pill-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
        .ws-terminal-spacer { flex: 1; }
        #ws-xterm { flex: 1; padding: 8px; }

        /* ─── Davinci panel ─── */
        .ws-davinci { max-width: 640px; background: var(--bg-elev); border: 1px solid var(--border);
            border-radius: 14px; padding: 24px; }
        .ws-davinci-status { display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 14px; border-radius: 999px; background: rgba(248,113,113,0.10);
            border: 1px solid rgba(248,113,113,0.25); color: var(--danger); font-size: 12px; font-weight: 700; }
        .ws-davinci-status .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--danger); }
        .ws-davinci-status.is-on { background: rgba(52,211,153,0.10); border-color: rgba(52,211,153,0.25); color: var(--success); animation: wsPulse 1.6s infinite ease-in-out; }
        .ws-davinci-status.is-on .dot { background: var(--success); animation: wsBlink 1.4s infinite ease-out; }
        @keyframes wsPulse { 50% { filter: brightness(1.2); } }
        @keyframes wsBlink { 50% { transform: scale(1.5); opacity: 0.4; } }
        .ws-davinci-transcript { margin-top: 16px; padding: 16px; min-height: 80px;
            background: #000; border: 1px solid var(--border); border-radius: 10px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px;
            color: var(--text); white-space: pre-wrap; line-height: 1.5; }
        .ws-davinci-transcript em { color: var(--text-muted); font-style: italic; }
        .ws-davinci-help { margin-top: 14px; font-size: 12px; color: var(--text-muted); line-height: 1.6; }
        .ws-davinci-help code { background: rgba(167,139,250,0.10); color: var(--accent);
            padding: 1px 6px; border-radius: 4px; font-size: 11.5px; }

        /* ─── Gas Station tab ─── */
        .ws-gas-list { display: flex; flex-direction: column; gap: 10px; }
        .ws-gas-row { padding: 14px 18px; background: var(--bg-elev); border: 1px solid var(--border);
            border-radius: 10px; display: flex; align-items: center; gap: 12px; }
        .ws-gas-row a { color: var(--accent); text-decoration: none; font-weight: 700; }
        .ws-gas-row a:hover { text-decoration: underline; }
        .ws-gas-row-name { font-weight: 700; color: #fff; flex: 1; }

        /* ─── Site action menu + status modal ─── */
        .ws-site-menu-wrap { position: relative; margin-left: auto; }
        .ws-site-menu-btn { padding: 4px 9px; font-size: 16px; color: var(--text-muted); background: transparent; border: 1px solid var(--border); border-radius: 6px; cursor: pointer; line-height: 1; }
        .ws-site-menu-btn:hover { color: #fff; background: rgba(255,255,255,0.04); }
        .ws-site-menu { position: absolute; right: 0; top: 100%; margin-top: 6px; min-width: 220px;
            background: rgba(15,23,42,0.98); border: 1px solid var(--border); border-radius: 10px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.6); padding: 4px; z-index: 200; display: none; }
        .ws-site-menu.is-open { display: block; }
        .ws-site-menu-item { display: flex; align-items: center; gap: 8px; padding: 8px 12px; cursor: pointer;
            color: var(--text); background: transparent; border: 0; width: 100%; text-align: left; font-size: 12.5px;
            font-family: inherit; border-radius: 6px; }
        .ws-site-menu-item:hover { background: rgba(167,139,250,0.10); color: #fff; }
        .ws-site-menu-item.is-danger { color: var(--danger); }
        .ws-site-menu-item.is-danger:hover { background: rgba(248,113,113,0.10); }
        .ws-site-menu-divider { height: 1px; background: var(--border); margin: 4px 0; }
        .ws-site-menu-icon { width: 14px; flex-shrink: 0; opacity: 0.7; }

        /* Status modal */
        .ws-modal { position: fixed; inset: 0; background: rgba(2,6,12,0.78); backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px); z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .ws-modal[hidden] { display: none; }
        .ws-modal-dialog { width: 100%; max-width: 540px; background: linear-gradient(180deg, rgba(15,23,42,0.98), rgba(11,14,20,0.98));
            border: 1px solid rgba(167,139,250,0.30); border-radius: 16px; box-shadow: 0 30px 70px rgba(0,0,0,0.7);
            max-height: calc(100vh - 60px); display: flex; flex-direction: column; overflow: hidden; }
        .ws-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px;
            border-bottom: 1px solid var(--border); }
        .ws-modal-head h3 { margin: 0; font-size: 1.1rem; font-weight: 800; color: #fff; }
        .ws-modal-close { background: transparent; border: 0; color: var(--text-muted); font-size: 22px; cursor: pointer; padding: 4px 8px; }
        .ws-modal-close:hover { color: #fff; }
        .ws-modal-body { padding: 18px 22px; overflow-y: auto; font-size: 13px; line-height: 1.55; }
        .ws-modal-body dl { display: grid; grid-template-columns: 130px 1fr; gap: 6px 14px; margin: 0; }
        .ws-modal-body dt { color: var(--text-muted); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; padding-top: 1px; }
        .ws-modal-body dd { margin: 0; color: var(--text); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px;
            word-break: break-word; }
        .ws-modal-body dd .ws-pill { padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
            font-family: inherit; letter-spacing: 0.04em; }
        .ws-modal-body dd .ws-pill.is-up   { background: rgba(52,211,153,0.10); color: var(--success); border: 1px solid rgba(52,211,153,0.25); }
        .ws-modal-body dd .ws-pill.is-down { background: rgba(248,113,113,0.10); color: var(--danger);  border: 1px solid rgba(248,113,113,0.25); }
        .ws-modal-body .ws-banner { margin-top: 14px; padding: 10px 12px; background: rgba(251,191,36,0.08);
            border: 1px solid rgba(251,191,36,0.20); border-radius: 8px; color: #fbbf24; font-size: 12px; line-height: 1.5; }
        .ws-modal-body .ws-banner.is-ok { background: rgba(52,211,153,0.08); border-color: rgba(52,211,153,0.20); color: var(--success); }
        .ws-modal-body .ws-banner.is-err { background: rgba(248,113,113,0.08); border-color: rgba(248,113,113,0.20); color: var(--danger); }

        /* ─── Chat tab ─── */
        .ws-chat-setup { display: flex; align-items: flex-start; justify-content: center; padding: 30px 0; }
        .ws-chat-card  { max-width: 520px; padding: 24px 26px; background: var(--bg-elev); border: 1px solid var(--border); border-radius: 14px; }
        .ws-chat-card h3 { margin: 0 0 8px; font-size: 1.1rem; color: #fff; }
        .ws-chat-help { margin: 0 0 18px; font-size: 12.5px; color: var(--text-muted); line-height: 1.55; }
        .ws-chat-help a, .ws-chat-help code { color: var(--accent); }
        .ws-chat-help code { background: rgba(167,139,250,0.10); padding: 1px 6px; border-radius: 4px; font-size: 11.5px; }
        #ws-chat-key-form { display: flex; gap: 8px; flex-wrap: wrap; }
        #ws-chat-key-input { flex: 1; min-width: 220px; padding: 9px 12px; background: rgba(11,14,20,0.78); border: 1px solid var(--border); color: #fff; border-radius: 8px; font-size: 13px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; outline: none; }
        #ws-chat-key-input:focus { border-color: var(--accent); }
        #ws-chat-key-model { padding: 9px 12px; background: rgba(11,14,20,0.78); border: 1px solid var(--border); color: #fff; border-radius: 8px; font-size: 12px; }
        .ws-chat-status { margin: 12px 0 0; font-size: 12px; color: var(--text-muted); min-height: 16px; }
        .ws-chat-status.is-ok  { color: var(--success); }
        .ws-chat-status.is-err { color: var(--danger); }

        .ws-chat-conv { display: flex; flex-direction: column; height: calc(100vh - 130px); background: var(--bg-elev); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
        .ws-chat-toolbar { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid var(--border); }
        .ws-chat-model-pill { padding: 3px 10px; background: rgba(167,139,250,0.10); color: var(--accent); border-radius: 999px; font-size: 10.5px; font-weight: 700; font-family: ui-monospace, monospace; letter-spacing: 0.02em; }
        .ws-chat-spacer { flex: 1; }

        .ws-chat-messages { flex: 1; overflow-y: auto; padding: 18px 20px; display: flex; flex-direction: column; gap: 14px; }
        .ws-chat-msg { max-width: 720px; }
        .ws-chat-msg-role { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 4px; }
        .ws-chat-msg[data-role="user"]      .ws-chat-msg-role { color: var(--accent-2); }
        .ws-chat-msg[data-role="assistant"] .ws-chat-msg-role { color: var(--accent); }
        .ws-chat-msg[data-role="error"]     .ws-chat-msg-role { color: var(--danger); }
        .ws-chat-msg-body { padding: 12px 14px; background: rgba(0,0,0,0.22); border: 1px solid var(--border); border-radius: 10px; font-size: 13.5px; line-height: 1.6; color: var(--text); white-space: pre-wrap; word-break: break-word; }
        .ws-chat-msg[data-role="user"] .ws-chat-msg-body { background: rgba(34,211,238,0.06); border-color: rgba(34,211,238,0.18); }
        .ws-chat-msg[data-role="assistant"] .ws-chat-msg-body { background: rgba(167,139,250,0.06); border-color: rgba(167,139,250,0.18); }
        .ws-chat-msg[data-role="error"] .ws-chat-msg-body { background: rgba(248,113,113,0.08); border-color: rgba(248,113,113,0.25); color: var(--danger); }
        .ws-chat-msg-body .ws-chat-cursor { display: inline-block; width: 8px; height: 14px; background: var(--accent); margin-left: 2px; vertical-align: text-bottom; animation: wsCaret 1s infinite step-end; }
        @keyframes wsCaret { 50% { opacity: 0; } }

        .ws-chat-composer { padding: 12px 14px; border-top: 1px solid var(--border); background: rgba(0,0,0,0.18); display: flex; flex-direction: column; gap: 8px; }
        #ws-chat-input { width: 100%; padding: 10px 12px; background: rgba(11,14,20,0.78); border: 1px solid var(--border); color: #fff; border-radius: 8px; font-size: 13.5px; font-family: inherit; outline: none; resize: vertical; min-height: 60px; }
        #ws-chat-input:focus { border-color: var(--accent); }
        .ws-chat-composer-row { display: flex; align-items: center; gap: 10px; }
        .ws-chat-hint { font-size: 11px; color: var(--text-muted); flex: 1; }

        .ws-chat-tool, .ws-chat-tool-result { margin: 6px 0; padding: 8px 12px; background: rgba(0,0,0,0.35); border: 1px solid var(--border); border-radius: 8px; }
        .ws-chat-tool { border-left: 3px solid var(--accent); }
        .ws-chat-tool-result { border-left: 3px solid var(--accent-2); }
        .ws-chat-tool-result.is-error { border-left-color: var(--danger); background: rgba(248,113,113,0.06); }
        .ws-chat-tool-head { font-size: 10.5px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; }
        .ws-chat-tool pre, .ws-chat-tool-result pre { margin: 0; padding: 0; background: transparent; border: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11.5px; color: var(--text); white-space: pre-wrap; word-break: break-word; line-height: 1.45; }

        @media (max-width: 720px) {
            .ws-layout { grid-template-columns: 1fr; }
            .ws-sidenav { display: flex; flex-direction: row; padding: 6px; overflow-x: auto; gap: 4px; border-right: 0; border-bottom: 1px solid var(--border); }
            .ws-nav-item { white-space: nowrap; padding: 8px 12px; }
            .ws-nav-spacer, .ws-nav-foot { display: none; }
            .ws-content { padding: 14px; }
        }
    </style>
</head>
<body>

<div class="ws-topbar">
    <div class="ws-brand">
        <span class="ws-brand-dot"></span>
        <span class="ws-brand-name">GenD</span>
        <span class="ws-brand-sub">Web Shell</span>
    </div>
    <div class="ws-user">
        <span>signed in as <strong><?php echo esc_html( $user->display_name ?: $user->user_email ); ?></strong></span>
        <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Sign out</a>
    </div>
</div>

<div class="ws-layout">
    <nav class="ws-sidenav" aria-label="Web Shell navigation">
        <button class="ws-nav-item<?php if ( $tab === 'sites' ) echo ' is-active'; ?>" data-ws-tab="sites">
            <svg class="ws-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Sites
        </button>
        <button class="ws-nav-item<?php if ( $tab === 'terminal' ) echo ' is-active'; ?>" data-ws-tab="terminal">
            <svg class="ws-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
            Terminal
        </button>
        <button class="ws-nav-item<?php if ( $tab === 'chat' ) echo ' is-active'; ?>" data-ws-tab="chat">
            <svg class="ws-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            Chat
        </button>
        <button class="ws-nav-item<?php if ( $tab === 'gas-station' ) echo ' is-active'; ?>" data-ws-tab="gas-station">
            <svg class="ws-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            Gas Station
        </button>
        <button class="ws-nav-item<?php if ( $tab === 'davinci' ) echo ' is-active'; ?>" data-ws-tab="davinci">
            <svg class="ws-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v3M12 20v3M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M1 12h3M20 12h3M4.93 19.07l2.12-2.12M16.95 7.05l2.12-2.12"/><circle cx="12" cy="12" r="4"/></svg>
            Davinci
        </button>
        <div class="ws-nav-spacer"></div>
        <div class="ws-nav-foot">
            Web Shell v0.1 — mirrors the GenD Desktop App. Terminal isolation: per-user ephemeral container.
        </div>
    </nav>

    <main class="ws-content" id="ws-content">

        <!-- ─── Sites ─── -->
        <section class="ws-panel<?php if ( $tab === 'sites' ) echo ' is-active'; ?>" data-ws-panel="sites">
            <header class="ws-panel-head">
                <h1>Sites</h1>
                <p>All web apps your account has access to via gend.me memberships.</p>
            </header>
            <div id="ws-sites-loading" class="ws-loading">Loading sites…</div>
            <div id="ws-sites-grid" class="ws-sites-grid" hidden></div>
        </section>

        <!-- ─── Terminal ─── -->
        <section class="ws-panel<?php if ( $tab === 'terminal' ) echo ' is-active'; ?>" data-ws-panel="terminal">
            <header class="ws-panel-head">
                <h1>Terminal</h1>
                <p>Per-user ephemeral container with bash, git, kubectl, gcloud, npm.</p>
            </header>
            <div class="ws-terminal-wrap">
                <div class="ws-terminal-head">
                    <span class="ws-terminal-status" id="ws-term-status">connecting…</span>
                    <span style="color:var(--text-muted);font-size:11px;">Voice: <code style="background:rgba(167,139,250,0.10);color:var(--accent);padding:1px 5px;border-radius:4px;">Davinci, run npm test</code></span>
                    <div class="ws-terminal-spacer"></div>
                    <span class="ws-gcloud-pill" id="ws-gcloud-pill" data-state="unknown" title="gcloud SDK auth status">
                        <span class="ws-gcloud-pill-dot"></span>
                        <span id="ws-gcloud-pill-text">gcloud: checking…</span>
                    </span>
                    <button class="ws-btn ws-btn--ghost" id="ws-gcloud-action" hidden>Connect gcloud</button>
                    <button class="ws-btn ws-btn--ghost" id="ws-term-reconnect">Reconnect</button>
                </div>
                <div id="ws-xterm"></div>
            </div>
        </section>

        <!-- ─── Gas Station ─── -->
        <section class="ws-panel<?php if ( $tab === 'gas-station' ) echo ' is-active'; ?>" data-ws-panel="gas-station">
            <header class="ws-panel-head">
                <h1>Gas Station</h1>
                <p>Compute Gas + earnings across every group you administer.</p>
            </header>
            <div id="ws-gas-loading" class="ws-loading">Loading groups…</div>
            <div id="ws-gas-list" class="ws-gas-list" hidden></div>
        </section>

        <!-- ─── Chat ─── -->
        <section class="ws-panel<?php if ( $tab === 'chat' ) echo ' is-active'; ?>" data-ws-panel="chat">
            <header class="ws-panel-head">
                <h1>Chat</h1>
                <p>Claude streaming chat — your own Anthropic API key, your messages stay between you + Anthropic. (Tool use lands in a follow-up phase.)</p>
            </header>

            <div id="ws-chat-setup" class="ws-chat-setup" hidden>
                <div class="ws-chat-card">
                    <h3>Connect your Anthropic API key</h3>
                    <p class="ws-chat-help">
                        Paste an <code>sk-ant-…</code> key from <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>.
                        It's stored encrypted (AES-256-GCM) and only ever sent over HTTPS to Anthropic when you submit a message.
                    </p>
                    <form id="ws-chat-key-form" autocomplete="off">
                        <input type="password" id="ws-chat-key-input" placeholder="sk-ant-…" required spellcheck="false" />
                        <select id="ws-chat-key-model">
                            <option value="">Default (claude-opus-4-7)</option>
                            <option value="claude-opus-4-7">claude-opus-4-7</option>
                            <option value="claude-sonnet-4-6">claude-sonnet-4-6</option>
                            <option value="claude-haiku-4-5-20251001">claude-haiku-4-5</option>
                        </select>
                        <button type="submit" class="ws-btn ws-btn--primary">Save key</button>
                    </form>
                    <p class="ws-chat-status" id="ws-chat-key-status"></p>
                </div>
            </div>

            <div id="ws-chat-conv" class="ws-chat-conv" hidden>
                <div class="ws-chat-toolbar">
                    <span class="ws-chat-model-pill" id="ws-chat-model-pill">model: …</span>
                    <span class="ws-chat-spacer"></span>
                    <button class="ws-btn ws-btn--ghost" id="ws-chat-reset">New chat</button>
                    <button class="ws-btn ws-btn--ghost" id="ws-chat-disconnect">Disconnect API key</button>
                </div>
                <div class="ws-chat-messages" id="ws-chat-messages"></div>
                <form class="ws-chat-composer" id="ws-chat-composer">
                    <textarea id="ws-chat-input" rows="3" placeholder="Ask Claude anything… (Davinci voice: say &ldquo;Davinci, ask &lt;your question&gt;&rdquo;)"></textarea>
                    <div class="ws-chat-composer-row">
                        <span class="ws-chat-hint">Enter sends · Shift+Enter newline</span>
                        <button type="submit" class="ws-btn ws-btn--primary" id="ws-chat-send">Send</button>
                        <button type="button" class="ws-btn ws-btn--ghost" id="ws-chat-stop" hidden>Stop</button>
                    </div>
                </form>
            </div>
        </section>

        <!-- ─── Davinci ─── -->
        <section class="ws-panel<?php if ( $tab === 'davinci' ) echo ' is-active'; ?>" data-ws-panel="davinci">
            <header class="ws-panel-head">
                <h1>Davinci</h1>
                <p>Voice control for the Web Shell — same wake-word + shortcuts as the desktop app.</p>
            </header>
            <div class="ws-davinci">
                <button class="ws-btn ws-btn--primary" id="ws-davinci-toggle">🎙 Start Listening</button>
                <span class="ws-davinci-status" id="ws-davinci-status" style="margin-left:10px;"><span class="dot"></span> idle</span>
                <div class="ws-davinci-transcript" id="ws-davinci-transcript"><em>Press Start Listening, then say "Davinci, …" — anything after the wake word becomes the command.</em></div>
                <div class="ws-davinci-help">
                    Shortcuts:
                    <code>Davinci, send</code> ·
                    <code>Davinci, stop</code> ·
                    <code>Davinci, clear</code> ·
                    <code>Davinci, terminal</code> (switches to the Terminal tab) ·
                    <code>Davinci, sites</code> (switches to Sites)
                </div>
            </div>
        </section>

    </main>
</div>

<!-- Shared modal — reused for site status + future dialogs -->
<div class="ws-modal" id="ws-modal" hidden role="dialog" aria-modal="true" aria-labelledby="ws-modal-title">
    <div class="ws-modal-dialog">
        <header class="ws-modal-head">
            <h3 id="ws-modal-title">—</h3>
            <button class="ws-modal-close" id="ws-modal-close" aria-label="Close">&times;</button>
        </header>
        <div class="ws-modal-body" id="ws-modal-body"></div>
    </div>
</div>

<!-- Boot data + the SPA + xterm -->
<script>
window.__WS_BOOT__ = {
    restRoot:  <?php echo wp_json_encode( $rest_root ); ?>,
    nonce:     <?php echo wp_json_encode( $nonce ); ?>,
    user:      <?php echo wp_json_encode( array(
        'id'           => (int) $user->ID,
        'name'         => $user->display_name,
        'email'        => $user->user_email,
        'is_super'     => is_super_admin( $user->ID ),
    ) ); ?>,
    pingPath:    '/wp-json/gs/v1/web-shell/ping',
    sitesPath:   '/wp-json/gs/v1/web-shell/sites',
    groupsPath:  '/wp-json/gs/v1/web-shell/groups',
    termPath:    '/wp-json/gs/v1/web-shell/terminal',
    gcloudStatus:'/wp-json/gs/v1/web-shell/gcloud/status',
    gcloudStart: '/wp-json/gs/v1/web-shell/gcloud/start',
    gcloudOff:   '/wp-json/gs/v1/web-shell/gcloud/disconnect',
    sitePath:    '/wp-json/gs/v1/web-shell/sites/{id}',  /* + /status, /clone, /promote, /restart, /staging */
    chatStatus:  '/wp-json/gs/v1/web-shell/chat/status',
    chatKey:     '/wp-json/gs/v1/web-shell/chat/key',
    chatStream:  '/wp-json/gs/v1/web-shell/chat/stream'
};
</script>
<script src="https://cdn.jsdelivr.net/npm/xterm@5.5.0/lib/xterm.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.10.0/lib/xterm-addon-fit.min.js"></script>
<?php $js_path = dirname( __DIR__ ) . '/assets/web-shell.js'; ?>
<script src="<?php echo esc_url( plugins_url( 'assets/web-shell.js', dirname( __DIR__ ) . '/gend-society.php' ) ); ?>?v=<?php echo (int) ( file_exists( $js_path ) ? filemtime( $js_path ) : 0 ); ?>"></script>

</body>
</html>
        <?php
        exit;
    }
    add_action( 'template_redirect', 'gs_web_shell_render', 1 );
}

/* ════════════════════════════════════════════════════════════════════
   REST routes for the Web Shell SPA.
     /gs/v1/web-shell/ping     — auth probe (returns {user,version})
     /gs/v1/web-shell/sites    — list user's sites from memberships
     /gs/v1/web-shell/groups   — list groups the user admins (for Gas tab)
     /gs/v1/web-shell/terminal — Phase 22 WebSocket upgrade endpoint
                                 (stub for now — returns a contract
                                 description so the xterm.js client
                                 renders a clear "coming soon" banner)
   ════════════════════════════════════════════════════════════════════ */

if ( ! function_exists( 'gs_web_shell_register_rest' ) ) {

    function gs_web_shell_register_rest() {
        register_rest_route( 'gs/v1', '/web-shell/ping', array(
            'methods'             => 'GET',
            'callback'            => function () {
                if ( ! is_user_logged_in() ) return new WP_Error( 'no_user', 'Not logged in.', array( 'status' => 401 ) );
                $u = wp_get_current_user();
                return array(
                    'user'    => array( 'id' => (int) $u->ID, 'name' => $u->display_name, 'email' => $u->user_email ),
                    'version' => 'web-shell-0.1',
                    'time'    => gmdate( 'c' ),
                );
            },
            'permission_callback' => function () { return is_user_logged_in(); },
        ) );

        register_rest_route( 'gs/v1', '/web-shell/sites', array(
            'methods'             => 'GET',
            'callback'            => 'gs_web_shell_rest_sites',
            'permission_callback' => function () { return is_user_logged_in(); },
        ) );

        register_rest_route( 'gs/v1', '/web-shell/groups', array(
            'methods'             => 'GET',
            'callback'            => 'gs_web_shell_rest_groups',
            'permission_callback' => function () { return is_user_logged_in(); },
        ) );

        register_rest_route( 'gs/v1', '/web-shell/terminal', array(
            'methods'             => 'GET',
            'callback'            => 'gs_web_shell_rest_terminal',
            'permission_callback' => function () { return is_user_logged_in(); },
        ) );
    }
    add_action( 'rest_api_init', 'gs_web_shell_register_rest' );
}

if ( ! function_exists( 'gs_web_shell_rest_sites' ) ) {

    function gs_web_shell_rest_sites() {
        if ( ! function_exists( 'wu_get_customer_by' ) ) return array( 'sites' => array() );
        $user = wp_get_current_user();
        $customer = wu_get_customer_by( 'user_id', (int) $user->ID );
        if ( ! $customer ) return array( 'sites' => array() );
        $memberships = method_exists( $customer, 'get_memberships' ) ? (array) $customer->get_memberships() : array();
        $out = array();
        foreach ( $memberships as $m ) {
            if ( ! is_object( $m ) || ! method_exists( $m, 'get_sites' ) ) continue;
            $sites = (array) $m->get_sites();
            foreach ( $sites as $s ) {
                if ( ! is_object( $s ) ) continue;
                $title = method_exists( $s, 'get_title' ) ? (string) $s->get_title() : '';
                $hostname = method_exists( $s, 'get_meta' ) ? (string) $s->get_meta( 'gdc_container_hostname', '' ) : '';
                $logo  = method_exists( $s, 'get_featured_image' ) ? (string) $s->get_featured_image( 'thumbnail' ) : '';
                $url   = $hostname !== '' ? ( 'https://' . $hostname . '/' )
                    : ( method_exists( $s, 'get_active_site_url' ) ? (string) $s->get_active_site_url() : '' );
                $out[] = array(
                    'membership_id' => method_exists( $m, 'get_id' ) ? (int) $m->get_id() : 0,
                    'site_id'       => method_exists( $s, 'get_id' ) ? (int) $s->get_id() : 0,
                    'title'         => $title ?: 'Untitled Site',
                    'host'          => $hostname,
                    'url'           => $url,
                    'admin_url'     => $url ? trailingslashit( $url ) . 'wp-admin/' : '',
                    'logo'          => $logo,
                    'status'        => method_exists( $m, 'get_status' ) ? (string) $m->get_status() : '',
                );
            }
        }
        // Super-admin sees every site they manage even without a membership row,
        // mirrors the desktop sidebar's behavior.
        if ( is_super_admin( $user->ID ) && function_exists( 'wu_get_sites' ) && empty( $out ) ) {
            $sites = (array) wu_get_sites( array( 'number' => 100 ) );
            foreach ( $sites as $s ) {
                if ( ! is_object( $s ) ) continue;
                $hostname = method_exists( $s, 'get_meta' ) ? (string) $s->get_meta( 'gdc_container_hostname', '' ) : '';
                $url = $hostname !== '' ? ( 'https://' . $hostname . '/' ) : '';
                $out[] = array(
                    'membership_id' => 0,
                    'site_id'       => method_exists( $s, 'get_id' ) ? (int) $s->get_id() : 0,
                    'title'         => method_exists( $s, 'get_title' ) ? (string) $s->get_title() : 'Untitled',
                    'host'          => $hostname,
                    'url'           => $url,
                    'admin_url'     => $url ? $url . 'wp-admin/' : '',
                    'logo'          => '',
                    'status'        => 'network',
                );
            }
        }
        return array( 'sites' => $out );
    }
}

if ( ! function_exists( 'gs_web_shell_rest_groups' ) ) {

    function gs_web_shell_rest_groups() {
        if ( ! function_exists( 'groups_get_groups' ) ) return array( 'groups' => array() );
        $user_id = get_current_user_id();
        // Pull groups where current user is a group admin OR mod, plus
        // any if super-admin.
        $args = is_super_admin( $user_id )
            ? array( 'per_page' => 50, 'show_hidden' => true )
            : array( 'per_page' => 50, 'user_id' => $user_id, 'show_hidden' => true );
        $g = groups_get_groups( $args );
        $rows = array();
        if ( isset( $g['groups'] ) && is_array( $g['groups'] ) ) {
            foreach ( $g['groups'] as $group ) {
                if ( ! is_object( $group ) ) continue;
                $rows[] = array(
                    'id'        => (int) $group->id,
                    'name'      => (string) $group->name,
                    'slug'      => (string) $group->slug,
                    'gas_url'   => function_exists( 'bp_get_group_permalink' )
                        ? ( trailingslashit( bp_get_group_permalink( $group ) ) . 'compute-gas/' )
                        : '',
                );
            }
        }
        return array( 'groups' => $rows );
    }
}

/* ════════════════════════════════════════════════════════════════════
   Terminal endpoint — Phase 22.
   Returns either:
     { status: 'pending', message, ... }              when GS_SHELL_JWT_SECRET
                                                       isn't configured (PTY
                                                       backend not deployed yet)
     { status: 'ready', ws_url, token, session_id,    when both the secret
       container_id, expires_at, isolation,            and the optional
       capabilities }                                   GS_SHELL_PTY_WSS env are
                                                       set, in which case the
                                                       client opens the WS.
   ════════════════════════════════════════════════════════════════════ */

if ( ! function_exists( 'gs_web_shell_rest_terminal' ) ) {

    function gs_web_shell_rest_terminal() {
        $secret  = defined( 'GS_SHELL_JWT_SECRET' ) ? (string) GS_SHELL_JWT_SECRET
                 : ( getenv( 'GS_SHELL_JWT_SECRET' ) ?: '' );
        $wss_url = defined( 'GS_SHELL_PTY_WSS' )    ? (string) GS_SHELL_PTY_WSS
                 : ( getenv( 'GS_SHELL_PTY_WSS' )    ?: 'wss://shell.gend.me' );

        $contract_caps = array( 'bash', 'git', 'kubectl', 'gcloud', 'npm', 'node', 'docker' );

        if ( $secret === '' ) {
            // PTY backend not deployed yet — return the contract so the
            // xterm client renders a clear "coming soon" banner rather
            // than appearing broken.
            return array(
                'status'       => 'pending',
                'message'      => 'Per-user ephemeral container backend is queued. Once the web-shell-pty service is deployed + GS_SHELL_JWT_SECRET is set on this hub, this endpoint will mint a JWT and the terminal connects.',
                'isolation'    => 'per-user-ephemeral-container',
                'capabilities' => $contract_caps,
                'phase'        => 22,
            );
        }

        $user_id    = (int) get_current_user_id();
        $session_id = bin2hex( random_bytes( 12 ) );
        $now        = time();
        $payload    = array(
            'user_id'    => $user_id,
            'session_id' => $session_id,
            'iat'        => $now,
            'exp'        => $now + 10, // one-shot, 10-second window
            'jti'        => bin2hex( random_bytes( 8 ) ),
        );
        $token = gs_web_shell_jwt_encode( $payload, $secret );
        $expires_at = gmdate( 'c', $now + 10 );

        return array(
            'status'       => 'ready',
            'ws_url'       => rtrim( $wss_url, '/' ) . '/pty/' . $session_id,
            'token'        => $token,
            'session_id'   => $session_id,
            'container_id' => 'ws-u' . $user_id,
            'expires_at'   => $expires_at,
            'isolation'    => 'per-user-ephemeral-container',
            'capabilities' => $contract_caps,
        );
    }
}

if ( ! function_exists( 'gs_web_shell_jwt_encode' ) ) {

    /**
     * Minimal HS256 JWT encoder — avoids dragging in a Composer dep.
     * The PTY service verifies with the standard `jsonwebtoken` lib.
     */
    function gs_web_shell_jwt_encode( $payload, $secret ) {
        $b64 = function ( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); };
        $header  = $b64( wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) );
        $body    = $b64( wp_json_encode( $payload ) );
        $signing = $header . '.' . $body;
        $sig     = $b64( hash_hmac( 'sha256', $signing, $secret, true ) );
        return $signing . '.' . $sig;
    }
}

// ──────────────────────────────────────────────────────────────────
// REST gate allowlist — same pattern as /gs/v1/desktop/*.
// Public ping returns { status: not_logged_in } when unauthenticated
// instead of a generic 401, but the routes themselves still gate on
// is_user_logged_in() in their permission_callback.
// ──────────────────────────────────────────────────────────────────
add_filter( 'rest_authentication_errors', function ( $result ) {
    if ( ! is_wp_error( $result ) ) return $result;
    $route = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ( $route !== '' && strpos( $route, '/gs/v1/web-shell/' ) !== false ) {
        // Hand the route's own permission_callback the call; if the
        // user lacks auth it'll 401 from there with a meaningful body.
        return null;
    }
    return $result;
}, 100 );
