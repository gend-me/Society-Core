/**
 * Profile → Contracts → Growth Investments (gend-society inc/profile-growth-apps.php): AJAX search + select of the apps
 * the member is a Group Admin of; the chosen app's Marketing → Advertising page loads in the frame below.
 */
(function () {
    'use strict';

    function boot(root) {
        if (root.dataset.gsReady) return;
        root.dataset.gsReady = '1';
        var cfgEl = root.querySelector('[data-gs-growth-cfg]');
        var cfg = {};
        try { cfg = JSON.parse(cfgEl ? cfgEl.textContent : '{}'); } catch (e) {}
        var grid = root.querySelector('[data-gs-growth-grid]');
        if (!grid) return;
        var q = root.querySelector('[data-gs-growth-q]');
        var none = root.querySelector('[data-gs-growth-none]');
        var stage = root.querySelector('[data-gs-growth-stage]');
        var frame = root.querySelector('[data-gs-growth-frame]');
        var loading = root.querySelector('[data-gs-growth-loading]');
        var i18n = cfg.i18n || {};
        var KEY = 'gsGrowthApp:' + cfg.user;
        var current = 0;
        try { current = parseInt(localStorage.getItem(KEY), 10) || 0; } catch (e) {}

        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        function render(apps) {
            grid.innerHTML = apps.map(function (a) {
                var on = a.id === current;
                var initial = esc((a.name || '?').charAt(0).toUpperCase());
                return '<button type="button" class="gs-growth-app' + (on ? ' is-active' : '') + '" role="option" aria-selected="' + on + '" data-id="' + a.id + '" data-embed="' + esc(a.embed) + '">' +
                    '<span class="gs-growth-app__logo">' + (a.avatar ? '<img src="' + esc(a.avatar) + '" alt="" loading="lazy">' : initial) + '</span>' +
                    '<span class="gs-growth-app__txt"><strong>' + esc(a.name) + '</strong><small>' + esc(i18n[a.status] || a.status) + ' · Group Admin</small></span>' +
                    '<span class="gs-growth-app__check" aria-hidden="true">✓</span></button>';
            }).join('');
            none.hidden = apps.length > 0;
            none.textContent = i18n.none || '';
        }
        function select(btn) {
            if (!btn) return;
            current = parseInt(btn.dataset.id, 10) || 0;
            try { localStorage.setItem(KEY, String(current)); } catch (e) {}
            grid.querySelectorAll('.gs-growth-app').forEach(function (b) {
                var on = b === btn;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            stage.hidden = false;
            if (frame.getAttribute('src') !== btn.dataset.embed) {
                loading.hidden = false;
                frame.style.height = '';
                frame.setAttribute('src', btn.dataset.embed);
            }
        }
        frame.addEventListener('load', function () { loading.hidden = true; });

        grid.addEventListener('click', function (e) {
            var b = e.target.closest('.gs-growth-app');
            if (b) select(b);
        });

        var timer = 0, seq = 0;
        q.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var my = ++seq;
                var body = new URLSearchParams({ action: 'gend_society_growth_apps', nonce: cfg.nonce, user_id: cfg.user, q: q.value.trim() });
                root.classList.add('is-searching');
                fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (my !== seq) return;
                        render(res && res.success && res.data ? res.data.apps : []);
                    })
                    .catch(function () {})
                    .then(function () { if (my === seq) root.classList.remove('is-searching'); });
            }, 250);
        });

        // the embedded page reports its height, and where a popup opened inside it
        window.addEventListener('message', function (e) {
            if (e.source !== frame.contentWindow || e.origin !== cfg.origin || !e.data) return;
            if (e.data.gsEmbedHeight) frame.style.height = Math.max(400, e.data.gsEmbedHeight) + 'px';
            if (typeof e.data.gsEmbedFocus === 'number') {
                var top = frame.getBoundingClientRect().top + window.pageYOffset + e.data.gsEmbedFocus - window.innerHeight / 2;
                window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
            }
        });

        var apps = cfg.apps || [];
        render(apps);
        var pick = grid.querySelector('.gs-growth-app[data-id="' + current + '"]') || grid.querySelector('.gs-growth-app');
        // only load the frame once the Growth tab is opened (the panel is display:none until then)
        function maybeLoad() { if (root.offsetParent !== null && !frame.getAttribute('src')) select(pick); }
        var radio = document.getElementById('gci-tab-growth');
        if (radio) radio.addEventListener('change', maybeLoad);
        maybeLoad();
    }

    function all() { document.querySelectorAll('[data-gs-growth-apps]').forEach(boot); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
}());
