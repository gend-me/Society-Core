/**
 * Overview › Portfolio (gend-society inc/profile-portfolio-mosaic.php): filters, photo zoom, and the owner's
 * Add new popups — type cards, drag-and-drop upload dashboards, and the blog post dashboard with the block editor.
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
        var types = {};
        try { types = JSON.parse(root.getAttribute('data-types') || '{}') || {}; } catch (e) { types = {}; }
        var current = 'all';
        var changed = false;   // something was added — refresh counts when the popups close

        // Popups live on <body> so profile layout styles can't clip them.
        root.querySelectorAll('.gs-pf-modal').forEach(function (m) { document.body.appendChild(m); });
        function modal(name) { return document.querySelector('.gs-pf-modal[data-gs-pf-modal="' + name + '"]'); }
        function closeAll() {
            document.querySelectorAll('.gs-pf-modal').forEach(function (m) { m.hidden = true; });
            document.body.classList.remove('gs-pf-modal-open');
            var stage = document.querySelector('[data-gs-pf-editor-stage]');
            if (stage) {
                if (!stage.hidden) { location.hash = 'media'; location.reload(); return; }   // pick up the saved post
            }
        }
        function open(name) {
            document.querySelectorAll('.gs-pf-modal').forEach(function (m) { m.hidden = true; });
            var m = modal(name);
            if (!m) return;
            m.hidden = false;
            document.body.classList.add('gs-pf-modal-open');
        }

        function say(text, isError) { status.textContent = text || ''; status.classList.toggle('is-error', !!isError); }
        function post(fd) {
            return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).catch(function () { return null; });
        }

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

        function addTile(html, kind) {
            if (!html) return null;
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var tile = tmp.firstElementChild;
            if (!tile) return null;
            mosaic.insertBefore(tile, mosaic.firstChild);
            setFilter(current === 'all' ? 'all' : kind);
            return tile;
        }

        // ── Clicks (profile + popups) ────────────────────────────────────
        document.addEventListener('click', function (e) {
            var fb = e.target.closest('[data-gs-pf-filter]');
            if (fb && root.contains(fb)) { setFilter(fb.getAttribute('data-gs-pf-filter')); return; }

            var z = e.target.closest('[data-gs-pf-zoom]');
            if (z) {
                var box = root.querySelector('[data-gs-pf-zoom-box]');
                box.querySelector('img').src = z.getAttribute('data-gs-pf-zoom');
                box.hidden = false;
                return;
            }
            if (e.target.closest('[data-gs-pf-zoom-box]')) { root.querySelector('[data-gs-pf-zoom-box]').hidden = true; return; }

            var op = e.target.closest('[data-gs-pf-open]');
            if (op) {
                var what = op.getAttribute('data-gs-pf-open');
                if (what === 'addnew' || what === 'post') { open(what); return; }
                if (what === 'playlist') {
                    closeAll();
                    setFilter('playlist');
                    var nb = root.querySelector('[data-gs-pf-pane="playlist"] [data-action="new-playlist"]');
                    if (nb) nb.click();
                    return;
                }
                if (what.indexOf('media:') === 0) { openMedia(what.slice(6)); return; }
            }
            if (e.target.closest('[data-gs-pf-close]') || (e.target.classList && e.target.classList.contains('gs-pf-modal'))) { closeAll(); return; }

            var ed = e.target.closest('[data-gs-pf-editor]');
            if (ed) { openEditor(parseInt(ed.getAttribute('data-gs-pf-editor'), 10) || 0, ed); return; }
            if (e.target.closest('[data-gs-pf-editor-close]')) { location.hash = 'media'; location.reload(); return; }

            var rm = e.target.closest('[data-gs-pf-remove]');
            if (rm && root.contains(rm)) {
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
            var stage = document.querySelector('[data-gs-pf-editor-stage]');
            if (stage) { if (!stage.hidden) return; }   // Escape belongs to the editor while it's open
            closeAll();
        });

        // ── Media upload dashboard (photo / video / audio / file) ────────
        var mediaType = null, sessionCount = 0;
        var dash = document.querySelector('[data-gs-pf-media-dash]');
        function iconFor(kind) {
            var card = document.querySelector('.gs-pf-type--' + kind + ' .gs-pf-type__icon');
            return card ? card.innerHTML : '';
        }
        function openMedia(kind) {
            var t = types[kind];
            if (!t || !dash) return;
            mediaType = kind;
            sessionCount = 0;
            dash.className = 'gs-pf-modal__box gs-pf-modal__box--wide gs-pf-dash gs-pf-dash--' + kind;
            dash.querySelector('[data-gs-pf-media-icon]').innerHTML = iconFor(kind);
            dash.querySelector('[data-gs-pf-media-icon]').className = 'gs-pf-dash__icon gs-pf-type--' + kind;
            dash.querySelector('[data-gs-pf-drop-icon]').innerHTML = iconFor(kind);
            dash.querySelector('[data-gs-pf-media-title]').textContent = 'Add ' + t.plural.toLowerCase();
            dash.querySelector('[data-gs-pf-media-blurb]').textContent = t.blurb;
            dash.querySelector('[data-gs-pf-media-count]').textContent = mosaic.querySelectorAll('.gs-pf-tile[data-kind="' + kind + '"]').length;
            dash.querySelector('[data-gs-pf-media-session]').textContent = '0';
            dash.querySelector('[data-gs-pf-media-rules]').textContent = t.formats + ' · up to ' + t.max + ' MB each · add several at once';
            dash.querySelector('[data-gs-pf-files]').setAttribute('accept', t.accept);
            dash.querySelector('[data-gs-pf-queue]').innerHTML = '';
            renderRecent(kind);
            open('media');
        }
        function renderRecent(kind) {
            var grid = dash.querySelector('[data-gs-pf-recent]');
            grid.innerHTML = '';
            var tiles = mosaic.querySelectorAll('.gs-pf-tile[data-kind="' + kind + '"]');
            if (!tiles.length) { grid.innerHTML = '<p class="gs-pf-recent__none">Nothing yet — your first upload lands here.</p>'; return; }
            Array.prototype.slice.call(tiles, 0, 8).forEach(function (t) {
                var c = t.cloneNode(true);
                c.hidden = false;
                var x = c.querySelector('.gs-pf-remove'); if (x) x.remove();
                grid.appendChild(c);
            });
        }
        function fmtSize(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
        function uploadOne(file, row) {
            return new Promise(function (resolve) {
                var t = types[mediaType];
                if (file.size > t.max * 1048576) { row.classList.add('is-error'); row.querySelector('em').textContent = 'Larger than ' + t.max + ' MB'; resolve(); return; }
                var fd = new FormData();
                fd.append('action', 'gend_society_portfolio_upload');
                fd.append('nonce', nonce);
                fd.append('type', mediaType);
                fd.append('file', file);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajax);
                xhr.withCredentials = true;
                xhr.upload.onprogress = function (ev) {
                    if (!ev.lengthComputable) return;
                    var pct = Math.round(ev.loaded / ev.total * 100);
                    row.querySelector('i').style.width = pct + '%';
                    row.querySelector('em').textContent = pct < 100 ? pct + '%' : 'Processing…';
                };
                xhr.onload = function () {
                    var j = null; try { j = JSON.parse(xhr.responseText); } catch (err) { j = null; }
                    if (j && j.success) {
                        row.classList.add('is-done');
                        row.querySelector('i').style.width = '100%';
                        row.querySelector('em').textContent = 'Added';
                        addTile(j.data.html, j.data.kind);
                        sessionCount++;
                        changed = true;
                        dash.querySelector('[data-gs-pf-media-session]').textContent = sessionCount;
                        dash.querySelector('[data-gs-pf-media-count]').textContent = mosaic.querySelectorAll('.gs-pf-tile[data-kind="' + mediaType + '"]').length;
                        renderRecent(mediaType);
                    } else {
                        row.classList.add('is-error');
                        row.querySelector('em').textContent = (j && j.data && j.data.message) || 'Upload failed';
                    }
                    resolve();
                };
                xhr.onerror = function () { row.classList.add('is-error'); row.querySelector('em').textContent = 'Network error'; resolve(); };
                xhr.send(fd);
            });
        }
        function queueFiles(list) {
            if (!list || !list.length || !mediaType) return;
            var q = dash.querySelector('[data-gs-pf-queue]');
            var jobs = [];
            Array.prototype.forEach.call(list, function (f) {
                var li = document.createElement('li');
                li.innerHTML = '<span class="gs-pf-queue__name"></span><span class="gs-pf-queue__size"></span><span class="gs-pf-queue__bar"><i></i></span><em>Waiting…</em>';
                li.querySelector('.gs-pf-queue__name').textContent = f.name;
                li.querySelector('.gs-pf-queue__size').textContent = fmtSize(f.size);
                q.appendChild(li);
                jobs.push([f, li]);
            });
            // one at a time so large uploads don't compete
            jobs.reduce(function (chain, job) { return chain.then(function () { return uploadOne(job[0], job[1]); }); }, Promise.resolve());
        }
        if (dash) {
            var drop = dash.querySelector('[data-gs-pf-drop]');
            var input = dash.querySelector('[data-gs-pf-files]');
            input.addEventListener('change', function () { queueFiles(input.files); input.value = ''; });
            ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
            ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
            drop.addEventListener('drop', function (e) { if (e.dataTransfer) queueFiles(e.dataTransfer.files); });
        }

        // ── Blog posts: block editor inside the popup ────────────────────
        function openEditor(postId, btn) {
            var fd = new FormData();
            fd.append('action', 'gend_society_portfolio_editor');
            fd.append('nonce', nonce);
            fd.append('post_id', postId);
            if (btn) btn.disabled = true;
            post(fd).then(function (j) {
                if (btn) btn.disabled = false;
                if (!j || !j.success) { say((j && j.data && j.data.message) || 'Couldn’t open the editor.', true); return; }
                var m = modal('post');
                m.querySelector('[data-gs-pf-post-dash]').hidden = true;
                var stage = m.querySelector('[data-gs-pf-editor-stage]');
                stage.hidden = false;
                m.classList.add('is-editing');
                stage.querySelector('[data-gs-pf-editor-frame]').src = j.data.url;
            });
        }

        // Old #posts / #playlists links (those were Overview tabs) open Portfolio on the matching filter.
        var legacy = { '#posts': 'post', '#playlists': 'playlist' }[location.hash];
        if (legacy || location.hash === '#media') {
            var tab = document.querySelector('.gs-overview-tab[data-gs-ov-tab="media"]');
            if (tab) tab.click();
            if (legacy) { if (root.querySelector('[data-gs-pf-filter="' + legacy + '"]')) setFilter(legacy); }
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
