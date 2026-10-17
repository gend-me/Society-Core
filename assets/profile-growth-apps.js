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

        var known = {}, lastList = (cfg.apps || []).slice(), open = false;
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        function render(apps) {
            apps.forEach(function (a) { known[a.id] = a; });
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
            closeList();
        }
        // The list only opens from the search box; closed, just the chosen app's card shows under the search bar.
        function showSelected() {
            render(current && known[current] ? [known[current]] : []);
            none.hidden = true;
        }
        function openList() {
            if (open) return;
            open = true;
            root.classList.add('is-open');
            if (q.value.trim()) runSearch(); else render(lastList);
        }
        function closeList() {
            open = false;
            root.classList.remove('is-open');
            showSelected();
        }
        q.addEventListener('focus', openList);
        q.addEventListener('click', openList);
        q.addEventListener('keydown', function (e) { if (e.key === 'Escape') { q.blur(); closeList(); } });
        document.addEventListener('click', function (e) {
            if (open && !e.target.closest('.gs-growth-apps__search, [data-gs-growth-grid]')) closeList();
        });
        frame.addEventListener('load', function () { loading.hidden = true; });

        grid.addEventListener('click', function (e) {
            var b = e.target.closest('.gs-growth-app');
            if (!b) return;
            e.stopPropagation();   // re-rendering detaches the clicked card — keep the outside-click closer out of it
            if (!open) { openList(); q.focus(); return; }   // the chosen card reopens the list
            select(b);
        });

        var timer = 0, seq = 0;
        q.addEventListener('input', function () {
            if (!open) openList();
            clearTimeout(timer);
            timer = setTimeout(runSearch, 250);
        });
        function runSearch() {
                var my = ++seq;
                var body = new URLSearchParams({ action: 'gend_society_growth_apps', nonce: cfg.nonce, user_id: cfg.user, q: q.value.trim() });
                root.classList.add('is-searching');
                fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (my !== seq) return;
                        var list = res && res.success && res.data ? res.data.apps : [];
                        if (!q.value.trim()) lastList = list;
                        if (open) render(list); else list.forEach(function (a) { known[a.id] = a; });
                    })
                    .catch(function () {})
                    .then(function () { if (my === seq) root.classList.remove('is-searching'); });
        }

        // the embedded page reports its height, and where a popup opened inside it
        window.addEventListener('message', function (e) {
            if (e.source !== frame.contentWindow || e.origin !== cfg.origin || !e.data) return;
            if (e.data.gsEmbedHeight) frame.style.height = Math.max(400, e.data.gsEmbedHeight) + 'px';
            if (typeof e.data.gsEmbedFocus === 'number') {
                var top = frame.getBoundingClientRect().top + window.pageYOffset + e.data.gsEmbedFocus - window.innerHeight / 2;
                window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
            }
        });

        // ── Builds itself on scroll: staggered entrance as pieces come into view, exit when they drop back below the
        // screen (scrolling up), entrance again on the way down. The frame's visible slice goes to the embedded
        // Advertising page so its cards do the same (profile-growth-apps.php embed script).
        var RV = '.gs-growth-apps__head > div, .gs-growth-apps__search, .gs-growth-app, .gs-growth-apps__empty, .gs-growth-apps__stage';
        var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
        var io = (!reduce && 'IntersectionObserver' in window) ? new IntersectionObserver(function (entries) {
            var enter = entries.filter(function (en) { return en.isIntersecting; })
                .sort(function (a, b) { return a.boundingClientRect.top - b.boundingClientRect.top || a.boundingClientRect.left - b.boundingClientRect.left; });
            enter.forEach(function (en, i) { en.target.style.setProperty('--gs-rv-d', Math.min(i, 10) * 70 + 'ms'); en.target.classList.add('is-in'); });
            entries.forEach(function (en) {
                if (!en.isIntersecting && en.rootBounds && en.boundingClientRect.top > en.rootBounds.bottom) {
                    en.target.style.setProperty('--gs-rv-d', '0ms'); en.target.classList.remove('is-in');   // below the screen → exit
                }
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -6% 0px' }) : null;
        function arm(scope) {
            (scope || root).querySelectorAll(RV).forEach(function (el) {
                if (el.dataset.gsRv) return;
                el.dataset.gsRv = '1';
                if (io) io.observe(el); else el.classList.add('is-in');
            });
        }
        root.classList.add('gs-rv-on');
        if (window.MutationObserver) new MutationObserver(function () { arm(); }).observe(grid, { childList: true });
        var viewRaf = 0;
        function sendView() {
            viewRaf = 0;
            if (!frame.contentWindow || !frame.getAttribute('src') || frame.offsetParent === null) return;
            var r = frame.getBoundingClientRect();
            frame.contentWindow.postMessage({ gsView: { top: Math.max(0, -r.top), bottom: Math.max(0, Math.min(r.height, innerHeight - r.top)) } }, cfg.origin);
        }
        function kickView() { if (!viewRaf) viewRaf = requestAnimationFrame(sendView); }
        addEventListener('scroll', kickView, { passive: true });
        addEventListener('resize', kickView);
        frame.addEventListener('load', function () { setTimeout(sendView, 200); setTimeout(sendView, 900); });
        window.addEventListener('message', function (e) { if (e.source === frame.contentWindow && e.data && e.data.gsEmbedHeight) kickView(); });

        var apps = cfg.apps || [];
        apps.forEach(function (a) { known[a.id] = a; });
        if (!known[current] && apps.length) current = apps[0].id;
        showSelected();
        arm();
        // only load the frame once the Growth tab is opened (the panel is display:none until then)
        function maybeLoad() {
            if (root.offsetParent === null || frame.getAttribute('src')) return;
            var btn = grid.querySelector('.gs-growth-app[data-id="' + current + '"]');
            if (btn) select(btn);
        }
        var radio = document.getElementById('gci-tab-growth');
        if (radio) radio.addEventListener('change', maybeLoad);
        maybeLoad();
    }

    function all() { document.querySelectorAll('[data-gs-growth-apps]').forEach(boot); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
    document.addEventListener('gdc:pjax-load', all);   // content-only profile navigation (member-profile-header.php)
}());
