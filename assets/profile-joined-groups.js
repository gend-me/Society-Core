/**
 * Profile → Projects → Memberships → Joined (gend-society inc/profile-joined-groups.php): group-header cards with an
 * AJAX search and "Load more".
 */
(function () {
    'use strict';

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    function card(c, t) {
        var pill = t[c.status] || c.status;
        var cover = c.cover ? ' style="background-image:url(\'' + esc(c.cover).replace(/'/g, '%27') + '\')"' : '';
        var app = c.app
            ? '<div class="gs-jcard__mod"><span class="gs-jcard__lbl">' + esc(t.app) + '</span><strong>' + esc(c.app.name) + '</strong>' +
              (c.app.link ? '<a class="gs-jcard__site" href="' + esc(c.app.link) + '" target="_blank" rel="noopener noreferrer">' + esc(t.view) + '</a>' : '') + '</div>'
            : '<div class="gs-jcard__mod is-empty"><span class="gs-jcard__lbl">' + esc(t.app) + '</span><strong>' + esc(t.noApp) + '</strong></div>';
        var pm = '<div class="gs-jcard__mod gs-jcard__mod--pm">' +
            (c.pm && c.pm.avatar ? '<img src="' + esc(c.pm.avatar) + '" alt="" loading="lazy">' : '<span class="gs-jcard__pmi">' + esc(c.pm ? c.pm.name.charAt(0) : 'N') + '</span>') +
            '<span><span class="gs-jcard__lbl">' + esc(t.pm) + '</span><strong>' + esc(c.pm ? c.pm.name : t.noPm) + '</strong></span></div>';
        return '<article class="gs-jcard">' +
            '<a class="gs-jcard__cover" href="' + esc(c.link) + '"' + cover + '><span class="gs-jcard__shade"></span>' +
                '<span class="gs-jcard__id">' +
                    '<span class="gs-jcard__avatar">' + (c.avatar ? '<img src="' + esc(c.avatar) + '" alt="" loading="lazy">' : esc(c.name.charAt(0))) + '</span>' +
                    '<span class="gs-jcard__txt">' +
                        '<span class="gs-jcard__pills"><span class="gs-jcard__pill gs-jcard__pill--' + esc(c.status) + '">' + esc(pill) + '</span>' +
                        '<span class="gs-jcard__pill gs-jcard__pill--n" title="members"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 4a3 3 0 0 1 0 6M21 20a6 6 0 0 0-4-5.6"/></svg>' + c.members + '</span>' +
                        (c.is_admin ? '<span class="gs-jcard__pill gs-jcard__pill--admin">' + esc(t.admin) + '</span>' : '') + '</span>' +
                        '<strong class="gs-jcard__name">' + esc(c.name) + '</strong>' +
                        (c.desc ? '<span class="gs-jcard__desc">' + esc(c.desc) + '</span>' : '') +
                        (c.active ? '<span class="gs-jcard__when">' + esc(c.active) + '</span>' : '') +
                    '</span></span></a>' +
            '<div class="gs-jcard__mods">' + app + pm + '</div>' +
            '<a class="gs-jcard__open" href="' + esc(c.link) + '">' + esc(t.open) + ' →</a>' +
            '</article>';
    }

    function boot(root) {
        if (root.dataset.gsReady) return;
        root.dataset.gsReady = '1';
        var cfg = {};
        try { cfg = JSON.parse(root.querySelector('[data-gs-joined-cfg]').textContent); } catch (e) {}
        var t = cfg.i18n || {};
        var grid = root.querySelector('[data-gs-joined-grid]');
        var none = root.querySelector('[data-gs-joined-none]');
        var more = root.querySelector('[data-gs-joined-more]');
        var q = root.querySelector('[data-gs-joined-q]');
        var page = 1, seq = 0, timer = 0;

        function paint(res, append) {
            var cards = (res && res.cards) || [];
            var html = cards.map(function (c) { return card(c, t); }).join('');
            if (append) grid.insertAdjacentHTML('beforeend', html); else grid.innerHTML = html;
            var emptyAll = !append && !cards.length;
            none.hidden = !emptyAll;
            none.textContent = q.value.trim() ? t.none : t.empty;
            more.hidden = !(res && res.more);
        }
        function fetchPage(p, append) {
            var my = ++seq;
            root.classList.add('is-loading');
            var body = new URLSearchParams({ action: 'gend_society_joined_groups', nonce: cfg.nonce, q: q.value.trim(), page: p });
            return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) { if (my === seq && res && res.success) { page = p; paint(res.data, append); } })
                .catch(function () {})
                .then(function () { if (my === seq) root.classList.remove('is-loading'); });
        }
        q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { fetchPage(1, false); }, 250); });
        more.addEventListener('click', function () { fetchPage(page + 1, true); });
        paint(cfg.first, false);
    }

    function all() { document.querySelectorAll('[data-gs-joined]').forEach(boot); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
    document.addEventListener('gdc:pjax-load', all);   // content-only profile navigation (member-profile-header.php)
}());
