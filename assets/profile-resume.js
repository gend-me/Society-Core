/**
 * Member Resume editor (gend-society inc/profile-resume.php): add/remove rows, company search (the member's
 * Integration Hubs or a typed-in company), logo uploads, Edit / Visitor view switch, Save via admin-ajax.
 */
(function () {
    'use strict';

    function boot() {
        var root = document.getElementById('gdc-resume-editor');
        if (!root || root.dataset.wired) return;
        root.dataset.wired = '1';

        var ajaxUrl = root.getAttribute('data-ajax');
        var nonce = root.getAttribute('data-nonce');
        var saveBtn = document.getElementById('gdc-resume-save');
        var statusEl = document.getElementById('gdc-resume-status');
        var groups = [];
        try { groups = JSON.parse(root.getAttribute('data-groups') || '[]') || []; } catch (e) { groups = []; }

        function setStatus(text, isError) {
            statusEl.className = 'gdc-resume-status' + (isError ? ' is-error' : '');
            statusEl.textContent = text;
        }
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        function initial(s) { s = String(s || '').trim(); return s ? s.charAt(0).toUpperCase() : '?'; }

        // ── Edit / Visitor view ──────────────────────────────────────────
        var editorEl = root.querySelector('[data-editor]');
        var previewEl = root.querySelector('[data-preview]');
        root.querySelectorAll('[data-mode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var preview = btn.getAttribute('data-mode') === 'preview';
                root.querySelectorAll('[data-mode]').forEach(function (b) {
                    var on = b === btn;
                    b.classList.toggle('is-active', on);
                    b.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                editorEl.hidden = preview;
                previewEl.hidden = !preview;
                saveBtn.hidden = preview;
            });
        });

        // ── Rows: add / remove ───────────────────────────────────────────
        root.addEventListener('click', function (e) {
            var add = e.target.closest('[data-add]');
            if (add) {
                var key = add.getAttribute('data-add');
                var tpl = root.querySelector('template[data-tpl="' + key + '"]');
                var rows = root.querySelector('[data-rows="' + key + '"]');
                if (tpl) {
                    if (rows) {
                        rows.appendChild(tpl.content.cloneNode(true));
                        var added = rows.lastElementChild;
                        var f = added ? added.querySelector('input:not([type="hidden"]):not([type="file"]),textarea') : null;
                        if (f) f.focus();
                    }
                }
                return;
            }
            var rm = e.target.closest('[data-remove]');
            if (rm) {
                var row = rm.closest('.gdc-resume-row');
                if (row) row.remove();
                return;
            }
            var change = e.target.closest('[data-company-change]');
            if (change) { chooseCompany(change.closest('[data-company]'), null); return; }
            var pick = e.target.closest('[data-company-pick]');
            if (pick) {
                var box = pick.closest('[data-company]');
                var gid = parseInt(pick.getAttribute('data-company-pick'), 10) || 0;
                var g = null;
                groups.forEach(function (x) { if (x.id === gid) g = x; });
                chooseCompany(box, g ? { name: g.name, group_id: g.id, img: g.avatar } : { name: pick.getAttribute('data-company-name') || '', group_id: 0, img: '' });
                return;
            }
            var clear = e.target.closest('[data-logo-clear]');
            if (clear) {
                var logo = clear.closest('[data-logo]');
                setLogo(logo, 0, '');
                return;
            }
        });

        // ── Company: search the member's Integration Hubs, or use a typed name ──
        function chooseCompany(box, c) {
            if (!box) return;
            var chosen = box.querySelector('[data-company-chosen]');
            var search = box.querySelector('[data-company-search]');
            var q = box.querySelector('[data-company-q]');
            var results = box.querySelector('[data-company-results]');
            var logoWrap = box.querySelector('[data-company-logo]');
            box.querySelector('[data-f="company"]').value = c ? c.name : '';
            box.querySelector('[data-f="group_id"]').value = c ? (c.group_id || 0) : 0;
            results.hidden = true;
            results.innerHTML = '';
            if (!c) {
                chosen.hidden = true;
                search.hidden = false;
                logoWrap.hidden = true;
                q.value = '';
                q.focus();
                return;
            }
            var img = box.querySelector('[data-company-img]');
            var logoId = 0, logoUrl = '';
            if (!c.group_id) {
                var lg = logoWrap.querySelector('[data-logo]');
                logoId = parseInt(lg.querySelector('[data-f="logo_id"]').value, 10) || 0;
                var li = lg.querySelector('[data-logo-img]');
                logoUrl = logoId ? (li.style.backgroundImage || '').replace(/^url\(["']?|["']?\)$/g, '') : '';
            }
            var url = c.group_id ? (c.img || '') : logoUrl;
            img.style.backgroundImage = url ? 'url("' + url + '")' : '';
            img.textContent = url ? '' : initial(c.name);
            box.querySelector('[data-company-name]').textContent = c.name;
            box.querySelector('[data-company-tag]').textContent = c.group_id ? 'Integration Hub' : '';
            chosen.hidden = false;
            search.hidden = true;
            logoWrap.hidden = !!c.group_id;
        }

        root.addEventListener('input', function (e) {
            var q = e.target.closest('[data-company-q]');
            if (!q) return;
            var box = q.closest('[data-company]');
            var results = box.querySelector('[data-company-results]');
            var term = q.value.trim();
            var t = term.toLowerCase();
            if (!term) { results.hidden = true; results.innerHTML = ''; return; }
            var html = '';
            var exact = false;
            groups.forEach(function (g) {
                if (String(g.name).toLowerCase().indexOf(t) === -1) return;
                if (String(g.name).toLowerCase() === t) exact = true;
                html += '<li><button type="button" data-company-pick="' + g.id + '">' +
                    '<span class="gdc-resume-company__img"' + (g.avatar ? ' style="background-image:url(&quot;' + esc(g.avatar) + '&quot;)"' : '') + '>' + (g.avatar ? '' : esc(initial(g.name))) + '</span>' +
                    '<span>' + esc(g.name) + '<small>Integration Hub</small></span></button></li>';
            });
            if (!exact) {
                html += '<li><button type="button" data-company-pick="0" data-company-name="' + esc(term) + '">' +
                    '<span class="gdc-resume-company__img is-new">+</span><span>Use “' + esc(term) + '”<small>Company without an Integration Hub</small></span></button></li>';
            }
            results.innerHTML = html;
            results.hidden = false;
        });
        root.addEventListener('keydown', function (e) {
            var q = e.target.closest('[data-company-q]');
            if (!q || e.key !== 'Enter') return;
            e.preventDefault();
            var first = q.closest('[data-company]').querySelector('[data-company-pick]');
            if (first) first.click();
        });

        // ── Logo upload (company without a hub, school) ──────────────────
        function setLogo(logo, id, url) {
            logo.querySelector('[data-f="logo_id"]').value = id || 0;
            var img = logo.querySelector('[data-logo-img]');
            img.style.backgroundImage = url ? 'url("' + url + '")' : '';
            logo.querySelector('[data-logo-label]').textContent = url ? 'Change image' : (logo.closest('[data-row="education"]') ? 'Upload school logo' : 'Upload company logo');
            logo.querySelector('[data-logo-clear]').hidden = !url;
            // keep the chosen company's badge in step with its uploaded logo
            var box = logo.closest('[data-company]');
            if (box) {
                var cimg = box.querySelector('[data-company-img]');
                cimg.style.backgroundImage = url ? 'url("' + url + '")' : '';
                cimg.textContent = url ? '' : initial(box.querySelector('[data-f="company"]').value);
            }
        }
        root.addEventListener('change', function (e) {
            var input = e.target.closest('[data-logo-file]');
            if (!input || !input.files || !input.files[0]) return;
            var logo = input.closest('[data-logo]');
            var label = logo.querySelector('[data-logo-label]');
            var before = label.textContent;
            label.textContent = 'Uploading…';
            var fd = new FormData();
            fd.append('action', 'gend_society_resume_upload');
            fd.append('nonce', nonce);
            fd.append('file', input.files[0]);
            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    input.value = '';
                    if (json && json.success) { setLogo(logo, json.data.id, json.data.url); }
                    else { label.textContent = before; setStatus((json && json.data && json.data.message) ? json.data.message : 'Upload failed.', true); }
                })
                .catch(function () { input.value = ''; label.textContent = before; setStatus('Upload failed — try again.', true); });
        });

        // ── Save ─────────────────────────────────────────────────────────
        function val(row, f) {
            var el = row.querySelector('[data-f="' + f + '"]');
            return el ? (el.value || '').trim() : '';
        }
        function collect() {
            var data = {
                headline: (document.getElementById('gdc-resume-headline').value || '').trim(),
                summary: (document.getElementById('gdc-resume-summary').value || '').trim(),
                experience: [], education: [], skills: [], links: []
            };
            root.querySelectorAll('[data-rows="experience"] .gdc-resume-row').forEach(function (r) {
                var typed = r.querySelector('[data-company-q]');
                var company = val(r, 'company') || (typed ? typed.value.trim() : '');
                data.experience.push({
                    company: company,
                    group_id: parseInt(val(r, 'group_id'), 10) || 0,
                    logo_id: parseInt(val(r, 'logo_id'), 10) || 0,
                    role: val(r, 'role'),
                    company_desc: val(r, 'company_desc'),
                    start: val(r, 'start'),
                    end: val(r, 'end'),
                    notes: val(r, 'notes')
                });
            });
            root.querySelectorAll('[data-rows="education"] .gdc-resume-row').forEach(function (r) {
                data.education.push({
                    school: val(r, 'school'),
                    credential: val(r, 'credential'),
                    year: val(r, 'year'),
                    logo_id: parseInt(val(r, 'logo_id'), 10) || 0
                });
            });
            root.querySelectorAll('[data-rows="skills"] .gdc-resume-row').forEach(function (r) { data.skills.push(val(r, 'value')); });
            root.querySelectorAll('[data-rows="links"] .gdc-resume-row').forEach(function (r) { data.links.push({ label: val(r, 'label'), url: val(r, 'url') }); });
            return data;
        }

        saveBtn.addEventListener('click', function () {
            saveBtn.disabled = true;
            setStatus('Saving…', false);
            var body = new URLSearchParams();
            body.set('action', 'gend_society_save_resume');
            body.set('nonce', nonce);
            body.set('data', JSON.stringify(collect()));
            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    saveBtn.disabled = false;
                    if (json && json.success) {
                        setStatus('Resume saved.', false);
                        var pb = root.querySelector('[data-preview-body]');
                        if (pb && json.data && typeof json.data.preview === 'string') pb.innerHTML = json.data.preview;
                    } else {
                        setStatus((json && json.data && json.data.message) ? json.data.message : 'Save failed.', true);
                    }
                })
                .catch(function () {
                    saveBtn.disabled = false;
                    setStatus('Network error — try again.', true);
                });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
