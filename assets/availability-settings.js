/**
 * gend-society — Availability Settings Panel (Phase 28-03).
 *
 * Mount: #gs-avail-settings inside #gs-calendar-app (Phase 26 calendar tab).
 * REST: GET + PUT /wp-json/gs/v1/calendar/availability (Plan 28-02).
 *
 * UI:
 *   - Timezone picker (datalist of common IANA names + free-text fallback; validated by server)
 *   - Mon-Sun working-hour grid: per day, list of {start, end} with [+ Add range] / [×] remove
 *   - Blocked ranges: list of {start_utc, end_utc, reason} with [+ Add] / [×] remove
 *   - Save button → PUT → on success dispatch 'gs:availability-changed' so calendar refreshes
 *
 * Pattern: vanilla JS createElement + textContent (no innerHTML for user data — Plan 26-02 precedent).
 */
(function () {
    'use strict';

    var ROOT_ID = 'gs-avail-settings';
    var NONCE   = (window.gsAvailNonce || window.wpApiSettings && window.wpApiSettings.nonce) || '';
    var REST    = ((window.wpApiSettings && window.wpApiSettings.root) || '/wp-json/').replace(/\/$/, '') + '/gs/v1/calendar/availability';

    var DAYS = [
        { key: 'mon', label: 'Mon' },
        { key: 'tue', label: 'Tue' },
        { key: 'wed', label: 'Wed' },
        { key: 'thu', label: 'Thu' },
        { key: 'fri', label: 'Fri' },
        { key: 'sat', label: 'Sat' },
        { key: 'sun', label: 'Sun' }
    ];

    /* Phase 31-frontend — last GET payload, kept whole so SAVE can round-trip the
     * ENTIRE booking_settings object (the PUT replaces booking_settings_json
     * WHOLESALE — losing named_durations / enabled_meeting_types / any other key
     * = data loss). We merge ONLY .visibility into a deep-cloned copy on save. */
    var _lastState = null;

    /* visibility defaults — mirror the server's always-defaulted shape so the UI
     * renders coherently even if a (legacy) booking_settings has no visibility. */
    function defaultVisibility() {
        return {
            privacy: 'private',
            detail: 'busy',
            sources: { projects: true, campaigns: true, meetings: true },
            identity: { name: true, avatar: true, bio: false, timezone: true }
        };
    }

    /** Coerce whatever the server returned into a fully-populated visibility obj. */
    function normalizeVisibility(v) {
        var d = defaultVisibility();
        v = (v && typeof v === 'object') ? v : {};
        var src = (v.sources && typeof v.sources === 'object') ? v.sources : {};
        var id  = (v.identity && typeof v.identity === 'object') ? v.identity : {};
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
            }
        };
    }

    function homeBase() {
        // Derive site origin/path so the share URL matches home_url() without a localize.
        // wpApiSettings.root is .../wp-json/ on the same host; strip wp-json/ to get home.
        var root = (window.wpApiSettings && window.wpApiSettings.root) || (location.origin + '/');
        return String(root).replace(/wp-json\/?$/, '');
    }
    function shareUrl(token) {
        if (!token) { return ''; }
        return homeBase().replace(/\/$/, '') + '/calendar-view/' + token + '/';
    }

    // Common IANA list (not exhaustive — server validates so free-text is allowed)
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

    function buildPanel(state) {
        var root = document.getElementById(ROOT_ID);
        if (!root) return;
        _lastState = state || {}; // keep whole payload for SAVE round-trip
        root.textContent = ''; // clear

        var panel = el('div', { class: 'gs-avail-settings' });

        panel.appendChild(el('h3', { class: 'gs-avail-title', text: 'Availability Settings' }));

        // Timezone picker — datalist for autocomplete, free-text fallback
        var tzWrap = el('div', { class: 'gs-avail-row gs-avail-tz' });
        tzWrap.appendChild(el('label', { for: 'gs-avail-tz-input', text: 'Timezone (IANA, e.g. America/Toronto)' }));
        var tzInput = el('input', {
            id: 'gs-avail-tz-input',
            type: 'text',
            list: 'gs-avail-tz-list',
            value: state.timezone || '',
            placeholder: 'America/Toronto'
        });
        var tzList = el('datalist', { id: 'gs-avail-tz-list' });
        COMMON_TZS.forEach(function (tz) { tzList.appendChild(el('option', { value: tz })); });
        tzWrap.appendChild(tzInput);
        tzWrap.appendChild(tzList);
        panel.appendChild(tzWrap);

        // Working hours grid
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

        // Blocked ranges
        panel.appendChild(el('h4', { class: 'gs-avail-subtitle', text: 'Blocked Time (one-off)' }));
        var brWrap = el('div', { class: 'gs-avail-blocked' });
        var brList = el('div', { class: 'gs-avail-br-list' });
        (state.blocked_ranges || []).forEach(function (r) {
            brList.appendChild(buildBlockedRow(r.start_utc, r.end_utc, r.reason));
        });
        var addBr = el('button', { type: 'button', class: 'gs-avail-add-br', text: '+ Add blocked range' });
        addBr.addEventListener('click', function () {
            brList.appendChild(buildBlockedRow('', '', ''));
        });
        brWrap.appendChild(brList);
        brWrap.appendChild(addBr);
        panel.appendChild(brWrap);

        // ── Calendar Visibility section (Phase 31-frontend) ──
        var bs = (state && state.booking_settings && typeof state.booking_settings === 'object')
            ? state.booking_settings : {};
        panel.appendChild(buildVisibilitySection(normalizeVisibility(bs.visibility), state.share_token));

        // Save / status
        var actions = el('div', { class: 'gs-avail-actions' });
        var saveBtn = el('button', { type: 'button', class: 'gs-avail-save', text: 'Save Availability' });
        var status = el('span', { class: 'gs-avail-status', text: '' });
        actions.appendChild(saveBtn);
        actions.appendChild(status);
        panel.appendChild(actions);

        saveBtn.addEventListener('click', function () {
            status.textContent = 'Saving...';
            var payload = serializeForm(panel);
            // Round-trip booking_settings WHOLESALE: deep-clone the last-fetched
            // booking_settings (preserving named_durations / enabled_meeting_types /
            // any other keys) and merge ONLY the chosen visibility into it. The PUT
            // replaces booking_settings_json entirely, so anything dropped here is
            // lost on the server.
            var priorBs = (_lastState && _lastState.booking_settings && typeof _lastState.booking_settings === 'object')
                ? _lastState.booking_settings : {};
            var mergedBs;
            try { mergedBs = JSON.parse(JSON.stringify(priorBs)); }
            catch (e) { mergedBs = {}; }
            mergedBs.visibility = serializeVisibility(panel);
            payload.booking_settings = mergedBs;

            saveAvailability(payload).then(function (res) {
                if (res.ok) {
                    status.textContent = 'Saved.';
                    // Keep _lastState in sync with what the server now holds so a
                    // subsequent save re-merges from the freshest booking_settings.
                    if (res.body && typeof res.body === 'object') {
                        _lastState = res.body;
                    } else {
                        _lastState = payload;
                    }
                    // Update tz attribute on calendar root so renderer reads new tz immediately.
                    var app = document.getElementById('gs-calendar-app');
                    if (app && payload.timezone) app.setAttribute('data-tz', payload.timezone);
                    window.dispatchEvent(new CustomEvent('gs:availability-changed', { detail: res.body }));
                    setTimeout(function () { status.textContent = ''; }, 2500);
                } else {
                    var msg = (res.body && res.body.message) || ('error: ' + res.status);
                    status.textContent = msg;
                }
            }).catch(function (e) { status.textContent = 'Network error'; });
        });

        root.appendChild(panel);
    }

    /* ---- Calendar Visibility section (Phase 31-frontend) ------------------- */

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

        // Detail — full vs busy (disabled when Private).
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

        // Visible sources — checkboxes.
        var srcRow = el('div', { class: 'gs-avail-vis-row' });
        srcRow.appendChild(el('label', { class: 'gs-avail-vis-label', text: 'Visible sources to others' }));
        var srcGrid = el('div', { class: 'gs-avail-vis-checks', 'data-field': 'sources' });
        SOURCE_OPTS.forEach(function (opt) {
            srcGrid.appendChild(buildCheck('gs-vis-src-' + opt.key, opt.key, opt.label, !!vis.sources[opt.key]));
        });
        srcRow.appendChild(srcGrid);
        wrap.appendChild(srcRow);

        // Identity on shared page — checkboxes.
        var idRow = el('div', { class: 'gs-avail-vis-row' });
        idRow.appendChild(el('label', { class: 'gs-avail-vis-label', text: 'Identity on shared page' }));
        var idGrid = el('div', { class: 'gs-avail-vis-checks', 'data-field': 'identity' });
        IDENTITY_OPTS.forEach(function (opt) {
            idGrid.appendChild(buildCheck('gs-vis-id-' + opt.key, opt.key, opt.label, !!vis.identity[opt.key]));
        });
        idRow.appendChild(idGrid);
        wrap.appendChild(idRow);

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
        var privHint = el('div', { class: 'gs-avail-share-hint', text: 'Set privacy to "Anyone with link" or "Public" to share this calendar.' });
        linkRow.appendChild(privHint);
        wrap.appendChild(linkRow);

        reflectPrivacy(wrap); // initial enable/disable state
        return wrap;
    }

    function buildCheck(id, key, label, checked) {
        var l = el('label', { class: 'gs-avail-check', for: id });
        var input = el('input', { type: 'checkbox', id: id, value: key });
        if (checked) { input.checked = true; }
        l.appendChild(input);
        l.appendChild(el('span', { text: label }));
        return l;
    }

    /** Grey-out detail when privacy=Private; toggle the share-link hint. */
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

    /** Read the visibility section back into the locked visibility shape. */
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
            }
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
        // datetime-local expects YYYY-MM-DDTHH:MM in viewer's local clock
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function localInputToUtc(s) {
        if (!s) return '';
        var d = new Date(s); // browser parses as local
        if (isNaN(d.getTime())) return '';
        return d.toISOString().replace(/\.\d+Z$/, 'Z');
    }

    function serializeForm(panel) {
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

    function init() {
        if (!document.getElementById(ROOT_ID)) return;
        fetchAvailability().then(buildPanel).catch(function () {
            var root = document.getElementById(ROOT_ID);
            if (root) root.textContent = 'Failed to load availability.';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
