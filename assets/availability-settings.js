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
            saveAvailability(payload).then(function (res) {
                if (res.ok) {
                    status.textContent = 'Saved.';
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
