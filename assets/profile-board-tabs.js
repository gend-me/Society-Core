/**
 * Projects → My Projects: Calendar + My Connections tabs appended right of My Tasks (gend-society
 * inc/profile-board-tabs.php). Each panel frames that page chrome-stripped, sized to its content; the board's own
 * delegated tab handler (projects plugin pm-admin.js) switches them. My Connections carries the connection count and
 * the pending-request count, refreshed every minute and whenever the frame changes.
 */
(function () {
    'use strict';
    var CFG = window.GS_BOARD_TABS || {};
    if (!CFG.tabs || !CFG.tabs.length) return;
    var frames = [];

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    function badge(c) {
        c = c || {};
        var html = '<span class="gs-board-count" title="' + esc((c.friends || 0) + '') + '">' + (c.friends || 0) + '</span>';
        if (c.requests) html += '<span class="gs-board-count gs-board-count--req" title="' + esc(c.requests + ' ' + (CFG.i18n || {}).requests) + '">+' + c.requests + '</span>';
        return html;
    }
    function paintCounts(c) {
        document.querySelectorAll('[data-gs-board-count]').forEach(function (el) { el.innerHTML = badge(c); });
    }
    function refreshCounts() {
        if (!CFG.ajax) return;
        var body = new URLSearchParams({ action: 'gend_society_board_counts', nonce: CFG.nonce });
        fetch(CFG.ajax, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) { if (res && res.success) paintCounts(res.data); })
            .catch(function () {});
    }

    function load(panel) {
        var f = panel.querySelector('iframe');
        if (f && !f.getAttribute('src')) f.setAttribute('src', f.dataset.src);
    }

    function inject(app) {
        if (app.dataset.gsBoardTabs) return;
        var nav = app.querySelector(':scope > .psoo-pm-tabs');
        var panels = app.querySelector(':scope > .psoo-pm-panels');
        if (!nav || !panels) return;
        app.dataset.gsBoardTabs = '1';
        CFG.tabs.forEach(function (t) {
            if (nav.querySelector('[data-tab="' + t.slug + '"]')) return;
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'psoo-pm-tab gs-board-tab';
            b.dataset.tab = t.slug;
            b.setAttribute('role', 'tab');
            b.setAttribute('aria-selected', 'false');
            b.innerHTML = '<span class="dashicons ' + esc(t.icon) + '"></span> ' + esc(t.label) + (t.count ? ' <span class="gs-board-counts" data-gs-board-count>' + badge(CFG.counts) + '</span>' : '');
            nav.appendChild(b);

            var p = document.createElement('div');
            p.className = 'psoo-pm-panel gs-board-panel';
            p.dataset.panel = t.slug;
            p.innerHTML = '<div class="gs-board-frame-wrap"><div class="gs-board-loading"><span></span>' + esc((CFG.i18n || {}).loading) + '</div>' +
                '<iframe class="gs-board-frame" title="' + esc(t.label) + '" data-src="' + esc(t.src) + '"></iframe></div>';
            panels.appendChild(p);
            var f = p.querySelector('iframe');
            frames.push(f);
            f.addEventListener('load', function () {
                var l = p.querySelector('.gs-board-loading'); if (l) l.remove();
                if (t.count) refreshCounts();
            });
            b.addEventListener('click', function () {
                // same switch PM.initTabs does (idempotent with it), so these tabs never depend on its init order
                nav.querySelectorAll(':scope > .psoo-pm-tab').forEach(function (x) { x.classList.toggle('psoo-pm-tab--active', x === b); x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
                panels.querySelectorAll(':scope > .psoo-pm-panel').forEach(function (x) { x.classList.toggle('psoo-pm-panel--active', x === p); });
                load(p);
            });
            if (/(^|[?&])tab=/.test(location.search) && new URLSearchParams(location.search).get('tab') === t.slug) setTimeout(function () { b.click(); }, 0);
        });
    }

    var css = document.createElement('style');
    css.textContent =
        '.gs-board-counts{display:inline-flex;gap:4px;margin-left:6px;vertical-align:middle}' +
        '.gs-board-count{min-width:20px;height:20px;padding:0 6px;border-radius:999px;display:inline-grid;place-items:center;background:rgba(255,255,255,.12);color:#fff;font:700 .7rem/1 Inter,system-ui,sans-serif}' +
        '.gs-board-count--req{background:#e11d48;box-shadow:0 0 0 0 rgba(225,29,72,.6);animation:gsBoardPing 2s ease-out infinite}' +
        '@keyframes gsBoardPing{0%{box-shadow:0 0 0 0 rgba(225,29,72,.55)}80%,100%{box-shadow:0 0 0 8px rgba(225,29,72,0)}}' +
        '.gs-board-frame-wrap{position:relative;min-height:420px}' +
        '.gs-board-frame{display:block;width:100%;height:80vh;border:0;background:transparent}' +
        '.gs-board-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:12px;color:#94a3b8}' +
        '.gs-board-loading span{width:22px;height:22px;border-radius:50%;border:3px solid rgba(255,255,255,.15);border-top-color:#00d2ff;animation:gsBoardSpin .8s linear infinite}' +
        '@keyframes gsBoardSpin{to{transform:rotate(360deg)}}' +
        '@media (prefers-reduced-motion: reduce){.gs-board-count--req{animation:none}}';
    document.head.appendChild(css);

    window.addEventListener('message', function (e) {
        if (e.origin !== CFG.origin || !e.data) return;
        var f = null;
        for (var i = 0; i < frames.length; i++) if (frames[i].contentWindow === e.source) f = frames[i];
        if (!f) return;
        if (e.data.gsEmbedHeight) f.style.height = Math.max(420, e.data.gsEmbedHeight) + 'px';
        if (typeof e.data.gsEmbedFocus === 'number') {
            var top = f.getBoundingClientRect().top + window.pageYOffset + e.data.gsEmbedFocus - window.innerHeight / 2;
            window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        }
    });

    function boot() {
        var app = document.getElementById('psoo-pm-app');
        if (app) inject(app);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
    // the board can be re-rendered (mobile PJAX nav); re-inject when it reappears
    new MutationObserver(function () { var a = document.getElementById('psoo-pm-app'); if (a && !a.dataset.gsBoardTabs) inject(a); })
        .observe(document.documentElement, { childList: true, subtree: true });
    setInterval(function () { if (!document.hidden) refreshCounts(); }, 60000);
}());
