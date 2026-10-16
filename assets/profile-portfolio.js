/**
 * Overview › Portfolio (gend-society inc/profile-portfolio-mosaic.php): filters, photo zoom, and the owner's
 * Add new flows (uploads, blog post composer, playlist).
 */
(function () {
    'use strict';

    function boot() {
        var root = document.querySelector('[data-gs-pf]');
        if (!root || root.dataset.wired) return;
        root.dataset.wired = '1';
        var ajax = root.getAttribute('data-ajax');
        var nonce = root.getAttribute('data-nonce');
        var status = root.querySelector('[data-gs-pf-status]');
        var mosaic = root.querySelector('[data-gs-pf-mosaic]');
        var empty = root.querySelector('[data-gs-pf-empty]');
        var current = 'all';

        function say(text, isError) { status.textContent = text || ''; status.classList.toggle('is-error', !!isError); }

        // ── Filters ──────────────────────────────────────────────────────
        function setFilter(f) {
            current = f;
            root.querySelectorAll('[data-gs-pf-filter]').forEach(function (b) {
                var on = b.getAttribute('data-gs-pf-filter') === f;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            var pane = (f === 'post' || f === 'playlist') ? f : 'mosaic';
            root.querySelectorAll('[data-gs-pf-pane]').forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-gs-pf-pane') === pane); });
            if (pane === 'mosaic') {
                var shown = 0;
                mosaic.querySelectorAll('.gs-pf-tile').forEach(function (t) {
                    var hit = f === 'all' || t.getAttribute('data-kind') === f;
                    t.hidden = !hit;
                    if (hit) shown++;
                });
                empty.hidden = shown > 0;
            }
        }
        root.addEventListener('click', function (e) {
            var fb = e.target.closest('[data-gs-pf-filter]');
            if (fb) { setFilter(fb.getAttribute('data-gs-pf-filter')); return; }

            var z = e.target.closest('[data-gs-pf-zoom]');
            if (z) {
                var box = root.querySelector('[data-gs-pf-zoom-box]');
                box.querySelector('img').src = z.getAttribute('data-gs-pf-zoom');
                box.hidden = false;
                return;
            }
            if (e.target.closest('[data-gs-pf-zoom-box]')) { root.querySelector('[data-gs-pf-zoom-box]').hidden = true; return; }

            var add = e.target.closest('[data-gs-pf-add]');
            var menu = root.querySelector('[data-gs-pf-add-menu]');
            if (add) { var open = menu.hidden; menu.hidden = !open; add.setAttribute('aria-expanded', open ? 'true' : 'false'); return; }
            if (menu) { if (!e.target.closest('[data-gs-pf-add-menu]')) menu.hidden = true; }

            if (e.target.closest('[data-gs-pf-new-playlist]')) {
                menu.hidden = true;
                setFilter('playlist');
                var nb = root.querySelector('[data-gs-pf-pane="playlist"] [data-action="new-playlist"]');
                if (nb) nb.click();
                return;
            }
            if (e.target.closest('[data-gs-pf-new-post]')) {
                menu.hidden = true;
                var m = root.querySelector('[data-gs-pf-post-modal]');
                m.hidden = false;
                var t = m.querySelector('input[name="title"]');
                if (t) t.focus();
                return;
            }
            if (e.target.closest('[data-gs-pf-close]') || e.target.matches('[data-gs-pf-post-modal]')) {
                root.querySelector('[data-gs-pf-post-modal]').hidden = true;
                return;
            }
            var rm = e.target.closest('[data-gs-pf-remove]');
            if (rm) {
                if (!rm.dataset.armed) { rm.dataset.armed = '1'; rm.textContent = 'Remove?'; setTimeout(function () { delete rm.dataset.armed; rm.innerHTML = '&times;'; }, 3000); return; }
                var fd = new FormData();
                fd.append('action', 'gend_society_portfolio_remove');
                fd.append('nonce', nonce);
                fd.append('id', rm.getAttribute('data-gs-pf-remove'));
                post(fd).then(function (j) {
                    if (j && j.success) { rm.closest('.gs-pf-tile').remove(); setFilter(current); say('Removed.'); }
                    else say((j && j.data && j.data.message) || 'Couldn’t remove that.', true);
                });
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var z = root.querySelector('[data-gs-pf-zoom-box]'); if (z) z.hidden = true;
            var m = root.querySelector('[data-gs-pf-post-modal]'); if (m) m.hidden = true;
        });

        function post(fd) {
            return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).catch(function () { return null; });
        }
        function addTile(html, kind) {
            if (!html) return;
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var tile = tmp.firstElementChild;
            if (!tile) return;
            mosaic.insertBefore(tile, mosaic.firstChild);
            setFilter(kind);
        }

        // ── Uploads (photo / video / audio / file) ───────────────────────
        root.addEventListener('change', function (e) {
            var input = e.target.closest('[data-gs-pf-upload]');
            if (!input || !input.files || !input.files[0]) return;
            var type = input.getAttribute('data-gs-pf-upload');
            root.querySelector('[data-gs-pf-add-menu]').hidden = true;
            say('Uploading ' + input.files[0].name + '…');
            var fd = new FormData();
            fd.append('action', 'gend_society_portfolio_upload');
            fd.append('nonce', nonce);
            fd.append('type', type);
            fd.append('file', input.files[0]);
            post(fd).then(function (j) {
                input.value = '';
                if (j && j.success) { addTile(j.data.html, j.data.kind); say('Added to your portfolio.'); }
                else say((j && j.data && j.data.message) || 'Upload failed.', true);
            });
        });

        // Old #posts / #playlists links (those were Overview tabs) open Portfolio on the matching filter.
        var legacy = { '#posts': 'post', '#playlists': 'playlist' }[location.hash];
        if (legacy) {
            var tab = document.querySelector('.gs-overview-tab[data-gs-ov-tab="media"]');
            if (tab) tab.click();
            if (root.querySelector('[data-gs-pf-filter="' + legacy + '"]')) setFilter(legacy);
        }

        // ── Blog post composer ───────────────────────────────────────────
        var form = root.querySelector('[data-gs-pf-post-form]');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = form.querySelector('button[type="submit"]');
                btn.disabled = true;
                var fd = new FormData(form);
                fd.append('action', 'gend_society_portfolio_post');
                fd.append('nonce', nonce);
                post(fd).then(function (j) {
                    btn.disabled = false;
                    if (j && j.success) {
                        form.reset();
                        root.querySelector('[data-gs-pf-post-modal]').hidden = true;
                        addTile(j.data.html, 'all');
                        say(j.data.message);
                    } else say((j && j.data && j.data.message) || 'Couldn’t save your post.', true);
                });
            });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
