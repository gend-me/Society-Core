/**
 * gend-society — Availability + Visibility Settings (Phase 28-03 + 31-frontend).
 *
 * Refactor: the settings UI now lives in TWO POPUP MODALS opened from the
 * calendar action bar (data-gs-avail-open / data-gs-vis-open), NOT the inline
 * panel that used to fill #gs-avail-settings.
 *
 *   • Availability modal  — timezone + Weekly Working Hours (7-col grid) + Blocked Time
 *   • Visibility   modal  — privacy segmented / detail / sources / identity / share-link
 *
 * Both modals mirror the schedule-meeting.js modal UX: an overlay appended to
 * <body>, aria-hidden toggling, glassmorphic card, close ×, backdrop-click +
 * ESC close, and a document-level click delegate that opens them.
 *
 * REST: GET + PUT /wp-json/gs/v1/calendar/availability (Plan 28-02).
 * Shared in-memory state (_lastState) is fetched once on init and kept WHOLE so
 * SAVE from EITHER modal round-trips the FULL payload (timezone, working_hours,
 * blocked_ranges, booking_settings) with only that modal's edits merged in —
 * so saving Visibility never wipes Working Hours and vice-versa.
 *
 * Pattern: vanilla JS createElement + textContent (no innerHTML for user data).
 */
(function () {
    'use strict';

    var NONCE = (window.gsAvailNonce || (window.wpApiSettings && window.wpApiSettings.nonce)) || '';
    var REST  = ((window.wpApiSettings && window.wpApiSettings.root) || '/wp-json/').replace(/\/$/, '') + '/gs/v1/calendar/availability';

    var DAYS = [
        { key: 'mon', label: 'Mon' },
        { key: 'tue', label: 'Tue' },
        { key: 'wed', label: 'Wed' },
        { key: 'thu', label: 'Thu' },
        { key: 'fri', label: 'Fri' },
        { key: 'sat', label: 'Sat' },
        { key: 'sun', label: 'Sun' }
    ];

    /* Shared in-memory state — the LAST GET (or PUT response) payload, kept whole
     * so SAVE can round-trip the ENTIRE booking_settings object (the PUT replaces
     * booking_settings_json WHOLESALE — losing named_durations / any other key =
     * data loss). We merge ONLY the editing modal's slice on save. */
    var _lastState = null;
    var _loaded = false;       // has the initial GET resolved?
    var _loadError = false;

    // Live modal element refs (built once on init, appended to <body>).
    var availModal = null;     // { overlay, body, onOpen }
    var visModal = null;

    /* ===================== Staggered on-scroll entrance reveals =================
     * Mirrors schedule-meeting.js setupReveals(): tag every meaningful item in a
     * modal's scroll container (the card) with [data-reveal] + a per-item --rev-i
     * stagger index, then add .is-in as each crosses into view. Items already on
     * screen when the modal opens cascade in immediately. GPU-only (transform +
     * opacity + blur); reduced-motion / no-IntersectionObserver → instant reveal.
     * Each modal keeps its own observer so we can disconnect on close (no leaks). */
    function setupReveals(modal) {
        var scrollEl = modal.card;
        if (modal._revealObserver) { modal._revealObserver.disconnect(); modal._revealObserver = null; }

        // Every item we want to cascade in, in document order. Mirrors the schedule
        // modal's granularity: title, each section row / day card / blocked row,
        // each vis row, member chips area, share row, and the save actions.
        var blocks = scrollEl.querySelectorAll(
            '.gs-avail-modal-title, .gs-avail-tz, .gs-avail-subtitle, ' +
            '.gs-avail-day-row, .gs-avail-blocked, ' +
            '.gs-avail-vis-help, .gs-avail-vis-row, .gs-avail-members, .gs-avail-vis-share, ' +
            '.gs-avail-actions'
        );
        for (var i = 0; i < blocks.length; i++) {
            blocks[i].setAttribute('data-reveal', '');
            blocks[i].style.setProperty('--rev-i', String(i % 10)); // cap delay growth
            blocks[i].classList.remove('is-in');
        }

        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce || typeof IntersectionObserver === 'undefined') {
            for (var j = 0; j < blocks.length; j++) { blocks[j].classList.add('is-in'); }
            return;
        }

        modal._revealObserver = new IntersectionObserver(function (entries) {
            for (var e = 0; e < entries.length; e++) {
                if (entries[e].isIntersecting) {
                    entries[e].target.classList.add('is-in');
                    modal._revealObserver.unobserve(entries[e].target);
                }
            }
        }, { root: scrollEl, threshold: 0.08, rootMargin: '0px 0px -5% 0px' });

        for (var k = 0; k < blocks.length; k++) { modal._revealObserver.observe(blocks[k]); }
    }

    /* visibility defaults — mirror the server's always-defaulted shape. */
    function defaultVisibility() {
        return {
            privacy: 'private',
            detail: 'busy',
            sources: { projects: true, campaigns: true, meetings: true },
            identity: { name: true, avatar: true, bio: false, timezone: true },
            members: []
        };
    }

    function normalizeVisibility(v) {
        var d = defaultVisibility();
        v = (v && typeof v === 'object') ? v : {};
        var src = (v.sources && typeof v.sources === 'object') ? v.sources : {};
        var id  = (v.identity && typeof v.identity === 'object') ? v.identity : {};
        // members arrive from GET as [{id,name,avatar_url}] objects; keep that
        // shape (de-duped by id, valid ids only) so we can render chips.
        var members = [];
        if (Array.isArray(v.members)) {
            var seen = {};
            v.members.forEach(function (m) {
                var id2 = m && typeof m === 'object' ? parseInt(m.id, 10) : parseInt(m, 10);
                if (!id2 || seen[id2]) { return; }
                seen[id2] = true;
                members.push({
                    id: id2,
                    name: (m && m.name) ? String(m.name) : ('User #' + id2),
                    avatar_url: (m && m.avatar_url) ? String(m.avatar_url) : ''
                });
            });
        }
        return {
            privacy: (v.privacy === 'link' || v.privacy === 'public') ? v.privacy : d.privacy,
            detail:  (v.detail === 'full') ? 'full' : 'busy',
            sources: {
                projects:  src.projects  !== undefined ? !!src.projects  : d.sources.projects,
                campaigns: src.campaigns !== undefined ? !!src.campaigns : d.sources.campaigns,
                meetings:  src.meetings  !== undefined ? !!src.meetings  : d.sources.meetings
            },
            identity: {
                name:     id.name     !== undefined ? !!id.name     : d.identity.name,
                avatar:   id.avatar   !== undefined ? !!id.avatar   : d.identity.avatar,
                bio:      id.bio      !== undefined ? !!id.bio      : d.identity.bio,
                timezone: id.timezone !== undefined ? !!id.timezone : d.identity.timezone
            },
            members: members
        };
    }

    /* Member-search REST base (sibling of the availability endpoint). */
    var MEMBER_SEARCH = REST.replace(/\/availability$/, '/members/search');

    function searchMembers(term) {
        var url = MEMBER_SEARCH + '?q=' + encodeURIComponent(term);
        return fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: NONCE ? { 'X-WP-Nonce': NONCE } : {}
        }).then(function (r) { return r.ok ? r.json() : []; })
          .then(function (j) { return Array.isArray(j) ? j : []; })
          .catch(function () { return []; });
    }

    function homeBase() {
        var root = (window.wpApiSettings && window.wpApiSettings.root) || (location.origin + '/');
        return String(root).replace(/wp-json\/?$/, '');
    }
    function shareUrl(token) {
        if (!token) { return ''; }
        return homeBase().replace(/\/$/, '') + '/calendar-view/' + token + '/';
    }

    var COMMON_TZS = [
        'UTC', 'America/Toronto', 'America/Halifax', 'America/Vancouver', 'America/New_York',
        'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/London',
        'Europe/Paris', 'Europe/Berlin', 'Asia/Tokyo', 'Asia/Singapore', 'Australia/Sydney'
    ];

    function el(tag, attrs, kids) {
        var n = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) {
            if (k === 'class') n.className = attrs[k];
            else if (k === 'text') n.textContent = attrs[k];
            else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') n.addEventListener(k.substring(2).toLowerCase(), attrs[k]);
            else n.setAttribute(k, attrs[k]);
        });
        if (kids) kids.forEach(function (c) { if (c) n.appendChild(c); });
        return n;
    }

    function fetchAvailability() {
        return fetch(REST, {
            method: 'GET',
            credentials: 'same-origin',
            headers: NONCE ? { 'X-WP-Nonce': NONCE } : {}
        }).then(function (r) { return r.json(); });
    }

    function saveAvailability(payload) {
        return fetch(REST, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': NONCE || ''
            },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
        });
    }

    /* ===================== Generic modal shell (mirrors schedule-meeting.js) ==== */

    // Builds an overlay (root .gs-avail-modal) appended to <body>, aria-hidden,
    // with a glassmorphic card (title + × close + a body container). Backdrop
    // click + ESC + × all close. Returns { overlay, card, body, open, close }.
    function buildModal(rootClass, titleText) {
        var body = el('div', { class: 'gs-avail-modal-body' });
        var closeBtn = el('button', {
            class: 'gs-avail-modal-close', type: 'button', 'aria-label': 'Close', text: '×'
        });
        var title = el('h2', { class: 'gs-avail-modal-title', text: titleText });
        var card = el('div', { class: 'gs-avail-modal-card' }, [closeBtn, title, body]);
        var overlay = el('div', { class: 'gs-avail-modal ' + rootClass, 'aria-hidden': 'true', role: 'dialog', 'aria-modal': 'true' }, [card]);

        var api = {
            overlay: overlay,
            card: card,
            body: body,
            open: function () { overlay.setAttribute('aria-hidden', 'false'); },
            close: function () {
                overlay.setAttribute('aria-hidden', 'true');
                // Tear down the reveal observer so we never leak across opens.
                if (api._revealObserver) { api._revealObserver.disconnect(); api._revealObserver = null; }
            }
        };

        closeBtn.addEventListener('click', api.close);
        // Backdrop click (only when the overlay itself, not the card, is clicked).
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { api.close(); } });

        document.body.appendChild(overlay);
        return api;
    }

    // Single ESC handler closes whichever modal is open.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' && e.keyCode !== 27) { return; }
        if (availModal && availModal.overlay.getAttribute('aria-hidden') === 'false') { availModal.close(); }
        if (visModal && visModal.overlay.getAttribute('aria-hidden') === 'false') { visModal.close(); }
    });

    /* ===================== AVAILABILITY modal body ============================= */

    function renderAvailabilityBody(state) {
        var body = availModal.body;
        body.textContent = '';

        var panel = el('div', { class: 'gs-avail-settings gs-avail-settings--modal' });

        // Timezone picker.
        var tzWrap = el('div', { class: 'gs-avail-row gs-avail-tz' });
        tzWrap.appendChild(el('label', { for: 'gs-avail-tz-input', text: 'Timezone (IANA, e.g. America/Toronto)' }));
        var tzInput = el('input', {
            id: 'gs-avail-tz-input', type: 'text', list: 'gs-avail-tz-list',
            value: state.timezone || '', placeholder: 'America/Toronto'
        });
        var tzList = el('datalist', { id: 'gs-avail-tz-list' });
        COMMON_TZS.forEach(function (tz) { tzList.appendChild(el('option', { value: tz })); });
        tzWrap.appendChild(tzInput);
        tzWrap.appendChild(tzList);
        panel.appendChild(tzWrap);

        // Working hours grid.
        panel.appendChild(el('h4', { class: 'gs-avail-subtitle', text: 'Weekly Working Hours' }));
        var whWrap = el('div', { class: 'gs-avail-wh-grid' });
        DAYS.forEach(function (day) {
            var dayRow = el('div', { class: 'gs-avail-day-row' });
            dayRow.appendChild(el('div', { class: 'gs-avail-day-label', text: day.label }));
            var rangesWrap = el('div', { class: 'gs-avail-ranges', 'data-day': day.key });
            var ranges = (state.working_hours && state.working_hours[day.key]) || [];
            ranges.forEach(function (r) { rangesWrap.appendChild(buildRangeRow(day.key, r.start, r.end)); });
            var addBtn = el('button', { type: 'button', class: 'gs-avail-add-range', text: '+ Add' });
            addBtn.addEventListener('click', function () {
                rangesWrap.insertBefore(buildRangeRow(day.key, '09:00', '17:00'), addBtn);
            });
            rangesWrap.appendChild(addBtn);
            dayRow.appendChild(rangesWrap);
            whWrap.appendChild(dayRow);
        });
        panel.appendChild(whWrap);

        // Blocked ranges.
        panel.appendChild(el('h4', { class: 'gs-avail-subtitle', text: 'Blocked Time (one-off)' }));
        var brWrap = el('div', { class: 'gs-avail-blocked' });
        var brList = el('div', { class: 'gs-avail-br-list' });
        (state.blocked_ranges || []).forEach(function (r) {
            brList.appendChild(buildBlockedRow(r.start_utc, r.end_utc, r.reason));
        });
        var addBr = el('button', { type: 'button', class: 'gs-avail-add-br', text: '+ Add blocked range' });
        addBr.addEventListener('click', function () { brList.appendChild(buildBlockedRow('', '', '')); });
        brWrap.appendChild(brList);
        brWrap.appendChild(addBr);
        panel.appendChild(brWrap);

        // Save / status.
        var actions = el('div', { class: 'gs-avail-actions' });
        var saveBtn = el('button', { type: 'button', class: 'gs-avail-save', text: 'Save Availability' });
        var status = el('span', { class: 'gs-avail-status', text: '' });
        actions.appendChild(saveBtn);
        actions.appendChild(status);
        panel.appendChild(actions);

        saveBtn.addEventListener('click', function () {
            status.textContent = 'Saving...';
            // Availability edits = timezone + working_hours + blocked_ranges.
            var avail = serializeAvailability(panel);
            var payload = mergePayload({
                timezone: avail.timezone,
                working_hours: avail.working_hours,
                blocked_ranges: avail.blocked_ranges
            });
            saveAvailability(payload).then(function (res) {
                if (res.ok) {
                    status.textContent = 'Saved.';
                    refreshState(res.body || payload);
                    var app = document.getElementById('gs-calendar-app');
                    if (app && payload.timezone) app.setAttribute('data-tz', payload.timezone);
                    window.dispatchEvent(new CustomEvent('gs:availability-changed', { detail: res.body }));
                    setTimeout(function () { status.textContent = ''; }, 2500);
                } else {
                    status.textContent = (res.body && res.body.message) || ('error: ' + res.status);
                }
            }).catch(function () { status.textContent = 'Network error'; });
        });

        body.appendChild(panel);
    }

    /* ===================== VISIBILITY modal body ============================== */

    function renderVisibilityBody(state) {
        var body = visModal.body;
        body.textContent = '';

        var panel = el('div', { class: 'gs-avail-settings gs-avail-settings--modal' });
        var bs = (state && state.booking_settings && typeof state.booking_settings === 'object') ? state.booking_settings : {};
        panel.appendChild(buildVisibilitySection(normalizeVisibility(bs.visibility), state.share_token));

        var actions = el('div', { class: 'gs-avail-actions' });
        var saveBtn = el('button', { type: 'button', class: 'gs-avail-save', text: 'Save Visibility' });
        var status = el('span', { class: 'gs-avail-status', text: '' });
        actions.appendChild(saveBtn);
        actions.appendChild(status);
        panel.appendChild(actions);

        saveBtn.addEventListener('click', function () {
            status.textContent = 'Saving...';
            // Round-trip booking_settings WHOLESALE: deep-clone prior booking_settings
            // (preserving named_durations / enabled_meeting_types / etc.) and merge
            // ONLY .visibility. The PUT replaces booking_settings_json entirely.
            var priorBs = (_lastState && _lastState.booking_settings && typeof _lastState.booking_settings === 'object')
                ? _lastState.booking_settings : {};
            var mergedBs;
            try { mergedBs = JSON.parse(JSON.stringify(priorBs)); } catch (e) { mergedBs = {}; }
            mergedBs.visibility = serializeVisibility(panel);
            var payload = mergePayload({ booking_settings: mergedBs });

            saveAvailability(payload).then(function (res) {
                if (res.ok) {
                    status.textContent = 'Saved.';
                    refreshState(res.body || payload);
                    window.dispatchEvent(new CustomEvent('gs:availability-changed', { detail: res.body }));
                    setTimeout(function () { status.textContent = ''; }, 2500);
                } else {
                    status.textContent = (res.body && res.body.message) || ('error: ' + res.status);
                }
            }).catch(function () { status.textContent = 'Network error'; });
        });

        body.appendChild(panel);
    }

    /* ===================== Full-payload round-trip helpers ===================== */

    // Build the FULL PUT payload from the shared state, overriding only the keys
    // the editing modal changed. Anything not overridden is taken from the last
    // fetched state so the OTHER modal's data is never wiped.
    function mergePayload(overrides) {
        var st = _lastState || {};
        var priorBs;
        try {
            priorBs = (st.booking_settings && typeof st.booking_settings === 'object')
                ? JSON.parse(JSON.stringify(st.booking_settings)) : {};
        } catch (e) { priorBs = {}; }

        var payload = {
            timezone: st.timezone || 'UTC',
            working_hours: (st.working_hours && typeof st.working_hours === 'object') ? st.working_hours : {},
            blocked_ranges: Array.isArray(st.blocked_ranges) ? st.blocked_ranges : [],
            booking_settings: priorBs
        };
        if (overrides) Object.keys(overrides).forEach(function (k) { payload[k] = overrides[k]; });
        return payload;
    }

    // Refresh the shared state from a PUT response (or fall back to the payload).
    function refreshState(next) {
        if (next && typeof next === 'object') { _lastState = next; }
    }

    /* ---- Calendar Visibility section ---------------------------------------- */

    var PRIVACY_OPTS = [
        { value: 'private', label: 'Private', hint: 'Only you' },
        { value: 'link',    label: 'Anyone with link', hint: 'Token-gated' },
        { value: 'public',  label: 'Public', hint: 'Discoverable' }
    ];
    var SOURCE_OPTS = [
        { key: 'projects',  label: 'Projects' },
        { key: 'campaigns', label: 'Campaigns' },
        { key: 'meetings',  label: 'Meetings' }
    ];
    var IDENTITY_OPTS = [
        { key: 'name',     label: 'Display name' },
        { key: 'avatar',   label: 'Avatar' },
        { key: 'bio',      label: 'Bio' },
        { key: 'timezone', label: 'Timezone' }
    ];

    function buildVisibilitySection(vis, shareToken) {
        var wrap = el('div', { class: 'gs-avail-visibility', 'data-section': 'visibility' });
        wrap.appendChild(el('h4', { class: 'gs-avail-subtitle', text: 'Calendar Visibility' }));
        wrap.appendChild(el('p', { class: 'gs-avail-vis-help',
            text: 'Control who can see your calendar and how much they see when you share the link.' }));

        // Privacy — segmented control (radios).
        var privRow = el('div', { class: 'gs-avail-vis-row' });
        privRow.appendChild(el('label', { class: 'gs-avail-vis-label', text: 'Privacy' }));
        var privSeg = el('div', { class: 'gs-avail-seg', 'data-field': 'privacy', role: 'radiogroup', 'aria-label': 'Privacy' });
        PRIVACY_OPTS.forEach(function (opt) {
            var id = 'gs-vis-priv-' + opt.value;
            var input = el('input', { type: 'radio', name: 'gs-vis-privacy', id: id, value: opt.value });
            if (vis.privacy === opt.value) { input.checked = true; }
            var lab = el('label', { class: 'gs-avail-seg-btn', for: id, title: opt.hint });
            lab.appendChild(el('span', { class: 'gs-avail-seg-main', text: opt.label }));
            lab.appendChild(el('span', { class: 'gs-avail-seg-hint', text: opt.hint }));
            input.addEventListener('change', function () { reflectPrivacy(wrap); });
            privSeg.appendChild(input);
            privSeg.appendChild(lab);
        });
        privRow.appendChild(privSeg);
        wrap.appendChild(privRow);

        // Detail — full vs busy.
        var detRow = el('div', { class: 'gs-avail-vis-row', 'data-field': 'detail-row' });
        detRow.appendChild(el('label', { class: 'gs-avail-vis-label', text: 'Detail shown to viewers' }));
        var detSeg = el('div', { class: 'gs-avail-seg', 'data-field': 'detail', role: 'radiogroup', 'aria-label': 'Detail shown to viewers' });
        [['full', 'Full details'], ['busy', 'Busy only']].forEach(function (pair) {
            var id = 'gs-vis-det-' + pair[0];
            var input = el('input', { type: 'radio', name: 'gs-vis-detail', id: id, value: pair[0] });
            if (vis.detail === pair[0]) { input.checked = true; }
            var lab = el('label', { class: 'gs-avail-seg-btn', for: id });
            lab.appendChild(el('span', { class: 'gs-avail-seg-main', text: pair[1] }));
            detSeg.appendChild(input);
            detSeg.appendChild(lab);
        });
        detRow.appendChild(detSeg);
        wrap.appendChild(detRow);

        // Visible sources.
        var srcRow = el('div', { class: 'gs-avail-vis-row' });
        srcRow.appendChild(el('label', { class: 'gs-avail-vis-label', text: 'Visible sources to others' }));
        var srcGrid = el('div', { class: 'gs-avail-vis-checks', 'data-field': 'sources' });
        SOURCE_OPTS.forEach(function (opt) {
            srcGrid.appendChild(buildCheck('gs-vis-src-' + opt.key, opt.key, opt.label, !!vis.sources[opt.key]));
        });
        srcRow.appendChild(srcGrid);
        wrap.appendChild(srcRow);

        // Identity on shared page.
        var idRow = el('div', { class: 'gs-avail-vis-row' });
        idRow.appendChild(el('label', { class: 'gs-avail-vis-label', text: 'Identity on shared page' }));
        var idGrid = el('div', { class: 'gs-avail-vis-checks', 'data-field': 'identity' });
        IDENTITY_OPTS.forEach(function (opt) {
            idGrid.appendChild(buildCheck('gs-vis-id-' + opt.key, opt.key, opt.label, !!vis.identity[opt.key]));
        });
        idRow.appendChild(idGrid);
        wrap.appendChild(idRow);

        // Share with specific members (Phase 42).
        wrap.appendChild(buildMembersSection(vis.members || []));

        // Share link row.
        var linkRow = el('div', { class: 'gs-avail-vis-row gs-avail-vis-share' });
        linkRow.appendChild(el('label', { class: 'gs-avail-vis-label', text: 'Share link' }));
        var url = shareUrl(shareToken);
        var linkBox = el('div', { class: 'gs-avail-share-box' });
        var urlInput = el('input', {
            type: 'text', class: 'gs-avail-share-url', readonly: 'readonly',
            value: url || '(share link unavailable)'
        });
        var copyBtn = el('button', { type: 'button', class: 'gs-avail-share-copy', text: 'Copy link' });
        var openLink = el('a', { class: 'gs-avail-share-open', text: 'Open', target: '_blank', rel: 'noopener' });
        if (url) { openLink.setAttribute('href', url); }
        copyBtn.addEventListener('click', function () {
            urlInput.select();
            var done = function () { copyBtn.textContent = 'Copied!'; setTimeout(function () { copyBtn.textContent = 'Copy link'; }, 1800); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done, function () { try { document.execCommand('copy'); done(); } catch (e) {} });
            } else {
                try { document.execCommand('copy'); done(); } catch (e) {}
            }
        });
        linkBox.appendChild(urlInput);
        linkBox.appendChild(copyBtn);
        linkBox.appendChild(openLink);
        linkRow.appendChild(linkBox);
        linkRow.appendChild(el('div', { class: 'gs-avail-share-hint',
            text: 'Set privacy to "Anyone with link" or "Public" to share this calendar.' }));
        wrap.appendChild(linkRow);

        reflectPrivacy(wrap);
        return wrap;
    }

    /* ---- Share-with-specific-members (Phase 42) ---------------------------- */

    // Builds the member-search section: debounced AJAX search input + results
    // dropdown + selected chips. Selected members are tracked on the section
    // node's _members array (read back by serializeMembers on save).
    function buildMembersSection(initial) {
        var wrap = el('div', { class: 'gs-avail-vis-row gs-avail-members', 'data-field': 'members' });
        wrap.appendChild(el('label', { class: 'gs-avail-vis-label', text: 'Share with specific members' }));
        wrap.appendChild(el('p', { class: 'gs-avail-members-help',
            text: 'These members can view your calendar even when privacy is Private.' }));

        // Live selection state lives on the node.
        var selected = [];
        var seen = {};
        (Array.isArray(initial) ? initial : []).forEach(function (m) {
            var id = m && typeof m === 'object' ? parseInt(m.id, 10) : parseInt(m, 10);
            if (!id || seen[id]) { return; }
            seen[id] = true;
            selected.push({ id: id, name: (m && m.name) ? String(m.name) : ('User #' + id), avatar_url: (m && m.avatar_url) ? String(m.avatar_url) : '' });
        });
        wrap._members = selected;

        var searchWrap = el('div', { class: 'gs-avail-members-search' });
        var input = el('input', {
            type: 'text', class: 'gs-avail-members-input',
            placeholder: 'Search members by name…', autocomplete: 'off'
        });
        var dropdown = el('div', { class: 'gs-avail-members-dropdown', 'aria-hidden': 'true' });
        searchWrap.appendChild(input);
        searchWrap.appendChild(dropdown);
        wrap.appendChild(searchWrap);

        var chips = el('div', { class: 'gs-avail-members-chips' });
        wrap.appendChild(chips);

        function renderChips() {
            chips.textContent = '';
            wrap._members.forEach(function (m) {
                var chip = el('span', { class: 'gs-avail-member-chip' });
                if (m.avatar_url) { chip.appendChild(el('img', { class: 'gs-avail-member-chip__av', src: m.avatar_url, alt: '' })); }
                chip.appendChild(el('span', { class: 'gs-avail-member-chip__name', text: m.name }));
                var rm = el('button', { type: 'button', class: 'gs-avail-member-chip__rm', 'aria-label': 'Remove', text: '×' });
                rm.addEventListener('click', function () {
                    wrap._members = wrap._members.filter(function (x) { return x.id !== m.id; });
                    renderChips();
                });
                chip.appendChild(rm);
                chips.appendChild(chip);
            });
        }

        function closeDropdown() {
            dropdown.textContent = '';
            dropdown.setAttribute('aria-hidden', 'true');
        }

        function addMember(m) {
            if (!m || !m.id) { return; }
            if (wrap._members.some(function (x) { return x.id === m.id; })) { return; } // no dupes
            wrap._members.push({ id: m.id, name: m.name || ('User #' + m.id), avatar_url: m.avatar_url || '' });
            renderChips();
            input.value = '';
            closeDropdown();
            input.focus();
        }

        function renderResults(results) {
            dropdown.textContent = '';
            if (!results.length) { closeDropdown(); return; }
            results.forEach(function (m) {
                var id = parseInt(m.id, 10);
                if (!id) { return; }
                var opt = el('button', { type: 'button', class: 'gs-avail-members-opt' });
                if (wrap._members.some(function (x) { return x.id === id; })) { opt.classList.add('is-selected'); }
                if (m.avatar_url) { opt.appendChild(el('img', { class: 'gs-avail-members-opt__av', src: m.avatar_url, alt: '' })); }
                opt.appendChild(el('span', { class: 'gs-avail-members-opt__name', text: m.name || ('User #' + id) }));
                opt.addEventListener('click', function () { addMember({ id: id, name: m.name, avatar_url: m.avatar_url }); });
                dropdown.appendChild(opt);
            });
            dropdown.setAttribute('aria-hidden', 'false');
        }

        var debounceTimer = null;
        input.addEventListener('input', function () {
            var term = input.value.trim();
            if (debounceTimer) { clearTimeout(debounceTimer); }
            if (term.length < 2) { closeDropdown(); return; }
            debounceTimer = setTimeout(function () {
                searchMembers(term).then(renderResults);
            }, 250);
        });
        // Close the dropdown on outside click.
        document.addEventListener('click', function (e) {
            if (!searchWrap.contains(e.target)) { closeDropdown(); }
        });

        renderChips();
        return wrap;
    }

    // Read the selected member IDs from the section node (sent on save as {id}).
    function serializeMembers(panel) {
        var wrap = panel.querySelector('[data-field="members"]');
        if (!wrap || !Array.isArray(wrap._members)) { return []; }
        return wrap._members.map(function (m) { return { id: m.id }; });
    }

    function buildCheck(id, key, label, checked) {
        var l = el('label', { class: 'gs-avail-check', for: id });
        var input = el('input', { type: 'checkbox', id: id, value: key });
        if (checked) { input.checked = true; }
        l.appendChild(input);
        l.appendChild(el('span', { text: label }));
        return l;
    }

    function reflectPrivacy(wrap) {
        var priv = (wrap.querySelector('input[name="gs-vis-privacy"]:checked') || {}).value || 'private';
        var isPrivate = (priv === 'private');
        var detRow = wrap.querySelector('[data-field="detail-row"]');
        if (detRow) {
            detRow.classList.toggle('is-disabled', isPrivate);
            detRow.querySelectorAll('input').forEach(function (i) { i.disabled = isPrivate; });
        }
        var share = wrap.querySelector('.gs-avail-vis-share');
        if (share) { share.classList.toggle('is-private', isPrivate); }
    }

    function serializeVisibility(panel) {
        var wrap = panel.querySelector('[data-section="visibility"]');
        if (!wrap) { return defaultVisibility(); }
        var priv = (wrap.querySelector('input[name="gs-vis-privacy"]:checked') || {}).value || 'private';
        var det  = (wrap.querySelector('input[name="gs-vis-detail"]:checked') || {}).value || 'busy';
        function readChecks(field) {
            var o = {};
            wrap.querySelectorAll('[data-field="' + field + '"] input[type="checkbox"]').forEach(function (i) {
                o[i.value] = !!i.checked;
            });
            return o;
        }
        var sources = readChecks('sources');
        var identity = readChecks('identity');
        return {
            privacy: (priv === 'link' || priv === 'public') ? priv : 'private',
            detail:  (det === 'full') ? 'full' : 'busy',
            sources: {
                projects:  !!sources.projects,
                campaigns: !!sources.campaigns,
                meetings:  !!sources.meetings
            },
            identity: {
                name:     !!identity.name,
                avatar:   !!identity.avatar,
                bio:      !!identity.bio,
                timezone: !!identity.timezone
            },
            members: serializeMembers(panel)
        };
    }

    function buildRangeRow(dayKey, start, end) {
        var row = el('span', { class: 'gs-avail-range', 'data-day': dayKey });
        var s = el('input', { type: 'time', class: 'gs-avail-range-start', value: start || '09:00' });
        var sep = el('span', { class: 'gs-avail-range-sep', text: '→' });
        var e = el('input', { type: 'time', class: 'gs-avail-range-end', value: end || '17:00' });
        var rm = el('button', { type: 'button', class: 'gs-avail-range-rm', text: '×' });
        rm.addEventListener('click', function () { row.parentNode.removeChild(row); });
        [s, sep, e, rm].forEach(function (n) { row.appendChild(n); });
        return row;
    }

    function buildBlockedRow(startUtc, endUtc, reason) {
        var row = el('div', { class: 'gs-avail-br-row' });
        var s = el('input', { type: 'datetime-local', class: 'gs-avail-br-start', value: utcToLocalInput(startUtc) });
        var e = el('input', { type: 'datetime-local', class: 'gs-avail-br-end', value: utcToLocalInput(endUtc) });
        var r = el('input', { type: 'text', class: 'gs-avail-br-reason', value: reason || '', placeholder: 'Reason (optional)', maxlength: 140 });
        var rm = el('button', { type: 'button', class: 'gs-avail-br-rm', text: '×' });
        rm.addEventListener('click', function () { row.parentNode.removeChild(row); });
        [s, e, r, rm].forEach(function (n) { row.appendChild(n); });
        return row;
    }

    function utcToLocalInput(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function localInputToUtc(s) {
        if (!s) return '';
        var d = new Date(s);
        if (isNaN(d.getTime())) return '';
        return d.toISOString().replace(/\.\d+Z$/, 'Z');
    }

    // Read the AVAILABILITY controls (timezone + working_hours + blocked_ranges).
    function serializeAvailability(panel) {
        var tz = (panel.querySelector('#gs-avail-tz-input') || {}).value || 'UTC';
        var wh = {};
        DAYS.forEach(function (day) {
            wh[day.key] = [];
            var rows = panel.querySelectorAll('.gs-avail-ranges[data-day="' + day.key + '"] .gs-avail-range');
            rows.forEach(function (row) {
                var s = row.querySelector('.gs-avail-range-start').value;
                var e = row.querySelector('.gs-avail-range-end').value;
                if (s && e && s < e) wh[day.key].push({ start: s, end: e });
            });
        });
        var br = [];
        panel.querySelectorAll('.gs-avail-br-row').forEach(function (row) {
            var s = localInputToUtc(row.querySelector('.gs-avail-br-start').value);
            var e = localInputToUtc(row.querySelector('.gs-avail-br-end').value);
            var r = row.querySelector('.gs-avail-br-reason').value || '';
            if (s && e && s < e) br.push({ start_utc: s, end_utc: e, reason: r });
        });
        return { timezone: tz, working_hours: wh, blocked_ranges: br };
    }

    /* ===================== Open delegates + init ============================== */

    function openAvailability() {
        if (!availModal) { return; }
        if (_loadError) { availModal.body.textContent = ''; availModal.body.appendChild(el('p', { text: 'Failed to load availability.' })); }
        else { renderAvailabilityBody(_lastState || {}); }
        availModal.open();
        // Wire the staggered on-scroll entrance reveals (card is the scroll container).
        setupReveals(availModal);
    }
    function openVisibility() {
        if (!visModal) { return; }
        if (_loadError) { visModal.body.textContent = ''; visModal.body.appendChild(el('p', { text: 'Failed to load availability.' })); }
        else { renderVisibilityBody(_lastState || {}); }
        visModal.open();
        // Wire the staggered on-scroll entrance reveals (card is the scroll container).
        setupReveals(visModal);
    }

    function init() {
        // Build both modal shells once (appended to <body>) so the open delegates
        // work even if clicked before the GET resolves.
        if (!availModal) { availModal = buildModal('gs-avail-modal--availability', 'Availability Settings'); }
        if (!visModal)   { visModal   = buildModal('gs-avail-modal--visibility', 'Calendar Visibility'); }

        fetchAvailability().then(function (state) {
            _lastState = state || {};
            _loaded = true;
            // If a modal is already open (clicked before load finished), refresh it
            // and re-wire the reveals against the freshly-rendered body.
            if (availModal.overlay.getAttribute('aria-hidden') === 'false') { renderAvailabilityBody(_lastState); setupReveals(availModal); }
            if (visModal.overlay.getAttribute('aria-hidden') === 'false') { renderVisibilityBody(_lastState); setupReveals(visModal); }
        }).catch(function () {
            _loadError = true;
            if (availModal.overlay.getAttribute('aria-hidden') === 'false') { openAvailability(); }
            if (visModal.overlay.getAttribute('aria-hidden') === 'false') { openVisibility(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Document-level open delegates (mirror schedule-meeting.js). Ensure the modal
    // shells exist even if the button is clicked before init ran.
    document.addEventListener('click', function (e) {
        var availT = e.target && e.target.closest ? e.target.closest('[data-gs-avail-open]') : null;
        if (availT) {
            e.preventDefault();
            if (!availModal) { init(); }
            openAvailability();
            return;
        }
        var visT = e.target && e.target.closest ? e.target.closest('[data-gs-vis-open]') : null;
        if (visT) {
            e.preventDefault();
            if (!visModal) { init(); }
            openVisibility();
        }
    });
})();
