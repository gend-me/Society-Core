/* global gsScheduleData */
/**
 * gend-society — Schedule Meeting modal (Phase 29 Plan 02).
 *
 * Vanilla-JS modal controller — no jQuery, no React. Mounts a "Schedule Meeting"
 * trigger button next to the profile-icon area, opens a glassmorphic modal with:
 *   - 4 meeting-type radio set (in_person / phone / video)
 *   - Duration picker (from member's named_durations, falls back to 15/30/60)
 *   - Per-type meta fields (address, phone+direction, provider+room_url)
 *   - Guest details (name, email, phone)
 *   - Visual date+time picker (glassmorphic calendar grid + clock dashboard)
 *     writing a hidden slot_start_local 'YYYY-MM-DDTHH:MM' field (→ UTC ISO Z at submit)
 *   - Submit → POST /gs/v1/calendar/meetings (X-WP-Nonce header)
 *   - On success: closes modal + dispatches CustomEvent('gs:meeting-created')
 *
 * Localization: window.gsScheduleData = { restUrl, nonce, namedDurations[] } —
 * injected via wp_localize_script in inc/member-calendar.php (Plan 29-02 Part D).
 */
(function () {
    'use strict';

    if (typeof window.gsScheduleData === 'undefined') { return; }

    // Tiny createElement helper — keeps the rest of the file readable without a framework.
    function ce(tag, attrs, kids) {
        var el = document.createElement(tag);
        attrs = attrs || {};
        for (var k in attrs) {
            if (!Object.prototype.hasOwnProperty.call(attrs, k)) { continue; }
            if (k === 'className') { el.className = attrs[k]; }
            else if (k === 'text') { el.textContent = attrs[k]; }
            else if (k.indexOf('on') === 0) { el.addEventListener(k.substring(2).toLowerCase(), attrs[k]); }
            else { el.setAttribute(k, attrs[k]); }
        }
        kids = Array.isArray(kids) ? kids : (kids ? [kids] : []);
        for (var i = 0; i < kids.length; i++) {
            var c = kids[i];
            if (!c && c !== 0) { continue; }
            el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
        return el;
    }

    var ScheduleMeeting = {
        state: { open: false, type: 'video', duration: 30, mounted: false, prefillStart: '' },
        modalRoot: null,
        metaContainer: null,

        // ── Visual date+time picker state ──
        // pick.selDate: Date at local midnight of the chosen day (or null).
        // pick.viewYear/viewMonth: the month currently displayed in the calendar grid.
        // pick.hour (0-23) / pick.minute (0/5/.../55): the chosen time.
        // pick.hiddenInput / pick.gridEl / pick.readoutEl / pick.clockEl / pick.titleEl:
        //   live element refs so we can repaint without a full modal re-render.
        pick: null,
        MINUTE_STEP: 5,

        // IntersectionObserver for staggered on-scroll entrance reveals.
        // Re-created each render() (the body is rebuilt); disconnected first so we
        // never leak observers across opens.
        revealObserver: null,

        // Inline SVG icons for the three meeting-type buttons. Hand-authored,
        // currentColor, ~28px viewBox. Wrapped in a <span> so we can inject HTML.
        typeIcons: {
            // Location / map-pin.
            in_person: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21.5c4.5-4.2 7-7.7 7-11a7 7 0 1 0-14 0c0 3.3 2.5 6.8 7 11z"/><circle cx="12" cy="10.5" r="2.6"/></svg>',
            // Phone handset.
            phone: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 3.5h3l1.4 4-2 1.4a12 12 0 0 0 5.2 5.2l1.4-2 4 1.4v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 5.7 2 2 0 0 1 6.5 3.5z"/></svg>',
            // Video camera.
            video: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6.5" width="12" height="11" rx="2.2"/><path d="M15 10.5l5-3v9l-5-3z"/></svg>'
        },

        mount: function () {
            if (this.state.mounted) { return; }
            this.state.mounted = true;
            var self = this;

            // Inject "Schedule Meeting" trigger near the profile-icon area. Tries
            // several known selectors so the button still surfaces if BP/Youzify
            // markup shifts. Mounts ONLY ONCE even if multiple selectors hit.
            var hostCandidates = [
                '#gs-profile-actions',
                '.gs-profile-icon-area',
                '#gs-calendar-app',
                '#item-header-content',
                '#wp-admin-bar-my-account'
            ];
            var triggerHost = null;
            for (var i = 0; i < hostCandidates.length; i++) {
                triggerHost = document.querySelector(hostCandidates[i]);
                if (triggerHost) { break; }
            }
            if (!triggerHost) { return; }

            if (triggerHost.querySelector('.gs-schedule-trigger')) { return; } // already mounted

            var btn = ce('button', {
                className: 'gs-schedule-trigger',
                text: 'Schedule Meeting',
                type: 'button',
                onclick: function () { self.openModal(); }
            });
            triggerHost.appendChild(btn);

            // Pre-build modal root once, append to body.
            // Modal markup root class is `gs-schedule-modal` (matched by assets/schedule-meeting.css).
            this.modalRoot = ce('div', { className: 'gs-schedule-modal', 'aria-hidden': 'true', role: 'dialog' });
            document.body.appendChild(this.modalRoot);
        },

        // openModal accepts an optional 'YYYY-MM-DDTHH:MM' local datetime string
        // (emitted by the calendar's gs:schedule-open event when a day/slot is
        // clicked). When present, render() seeds the slot-start field with it.
        openModal: function (prefillStart) {
            this.state.open = true;
            this.state.prefillStart = (typeof prefillStart === 'string') ? prefillStart : '';
            this.render();
            this.modalRoot.setAttribute('aria-hidden', 'false');
        },

        closeModal: function () {
            this.state.open = false;
            this.state.prefillStart = '';
            this.modalRoot.setAttribute('aria-hidden', 'true');
            // Tear down the reveal observer so we never leak across opens.
            if (this.revealObserver) { this.revealObserver.disconnect(); this.revealObserver = null; }
            this.modalRoot.innerHTML = '';
        },

        render: function () {
            var self = this;
            // Default named durations if member hasn't set any (BOOK-08 — server reads same).
            var named = (gsScheduleData.namedDurations && gsScheduleData.namedDurations.length)
                ? gsScheduleData.namedDurations
                : [
                    { label: 'Quick Sync',     minutes: 15 },
                    { label: 'Discovery Call', minutes: 30 },
                    { label: 'Workshop',       minutes: 60 }
                ];

            var closeBtn = ce('button', { className: 'gs-schedule-close', text: '×', type: 'button', 'aria-label': 'Close',
                                          onclick: function () { self.closeModal(); } });
            var title    = ce('h2', { className: 'gs-schedule-title', text: 'Schedule a meeting' });

            // ─── Meeting-type icon buttons ───
            // Three large glassmorphic <button>s (icon-over-label) replace the plain
            // radio set. aria-pressed reflects the active type; clicking selects the
            // type, repaints the selected state, and refreshes the per-type meta.
            var typeFieldset = ce('div', { className: 'gs-schedule-types', role: 'group', 'aria-label': 'Meeting type' });
            typeFieldset.appendChild(ce('span', { className: 'gs-schedule-types-legend', text: 'Meeting type' }));
            var types = [
                { value: 'in_person', label: 'In person' },
                { value: 'phone',     label: 'Phone' },
                { value: 'video',     label: 'Video' }
            ];
            var typeBtnRow = ce('div', { className: 'gs-schedule-types-row' });
            self._typeButtons = [];
            for (var ti = 0; ti < types.length; ti++) {
                (function (t) {
                    var isSel = (t.value === self.state.type);
                    var iconSpan = ce('span', { className: 'gs-schedule-type-icon' });
                    iconSpan.innerHTML = self.typeIcons[t.value] || '';
                    var btn = ce('button', {
                        type: 'button',
                        className: 'gs-schedule-type-btn' + (isSel ? ' is-selected' : ''),
                        'aria-pressed': isSel ? 'true' : 'false',
                        'data-type': t.value,
                        onclick: function () { self.selectType(t.value); }
                    }, [iconSpan, ce('span', { className: 'gs-schedule-type-label', text: t.label })]);
                    self._typeButtons.push(btn);
                    typeBtnRow.appendChild(btn);
                })(types[ti]);
            }
            typeFieldset.appendChild(typeBtnRow);

            // ─── Duration picker from named_durations (BOOK-08) ───
            var durSel = ce('select', { className: 'gs-schedule-duration', name: 'duration_min',
                                        onchange: function (e) { self.state.duration = parseInt(e.target.value, 10) || 30; } });
            for (var di = 0; di < named.length; di++) {
                var d = named[di];
                var opt = ce('option', { value: String(d.minutes), text: d.label + ' (' + d.minutes + ' min)' });
                if (d.minutes === self.state.duration) { opt.setAttribute('selected', 'selected'); }
                durSel.appendChild(opt);
            }

            // ─── Visual date+time picker (replaces the old native date/time input) ───
            // Builds a glassmorphic calendar grid + clock dashboard. Every change
            // writes a 'YYYY-MM-DDTHH:MM' local string into the hidden
            // slot_start_local input — the SAME field/format the submit handler reads.
            var slotField = self.buildPicker();

            // ─── Guest fields ───
            var guestFields = ce('div', { className: 'gs-schedule-guest' }, [
                ce('label', {}, [ce('span', { text: 'Guest name' }),
                                 ce('input', { type: 'text',  name: 'guest_name',  maxlength: '120', required: 'required' })]),
                ce('label', {}, [ce('span', { text: 'Guest email' }),
                                 ce('input', { type: 'email', name: 'guest_email', required: 'required' })]),
                ce('label', {}, [ce('span', { text: 'Guest phone (optional)' }),
                                 ce('input', { type: 'tel',   name: 'guest_phone' })])
            ]);

            // ─── Per-type meta container (re-rendered on type change) ───
            this.metaContainer = ce('div', { className: 'gs-schedule-meta' });

            var submitBtn = ce('button', { className: 'gs-schedule-submit', type: 'submit', text: 'Create meeting' });
            var errBox    = ce('div',    { className: 'gs-schedule-error',  role: 'alert' });

            // ─── Desktop multi-column layout ───
            // Top: meeting-type buttons span full width.
            // LEFT column: the date+time picker.
            // RIGHT column: duration + guest fields + per-type meta.
            // Bottom: submit + error span full width. Collapses to one column < 900px.
            var durBlock = ce('label', { className: 'gs-schedule-block' },
                              [ce('span', { text: 'Duration' }), durSel]);

            var leftCol  = ce('div', { className: 'gs-schedule-col gs-schedule-col-left' }, [slotField]);
            var rightCol = ce('div', { className: 'gs-schedule-col gs-schedule-col-right' },
                              [durBlock, guestFields, this.metaContainer]);

            var grid = ce('div', { className: 'gs-schedule-grid' }, [leftCol, rightCol]);

            var typeBlock   = ce('div', { className: 'gs-schedule-block gs-schedule-types-block' }, [typeFieldset]);
            var submitBlock = ce('div', { className: 'gs-schedule-block gs-schedule-submit-block' }, [submitBtn, errBox]);

            var form = ce('form', { className: 'gs-schedule-form',
                                    onsubmit: function (e) { self.onSubmit(e, form, errBox); } },
                [ typeBlock, grid, submitBlock ]);

            this.modalRoot.innerHTML = '';
            var card = ce('div', { className: 'gs-schedule-card' }, [closeBtn, title, form]);
            this.modalRoot.appendChild(card);
            this.renderTypeFields();

            // Wire the staggered on-scroll entrance reveals (card is the scroll container).
            this.setupReveals(card);
        },

        // Click handler for the meeting-type icon buttons: sync state, repaint the
        // selected/aria-pressed state across all three buttons, and refresh meta.
        selectType: function (value) {
            this.state.type = value;
            if (this._typeButtons) {
                for (var i = 0; i < this._typeButtons.length; i++) {
                    var b = this._typeButtons[i];
                    var on = (b.getAttribute('data-type') === value);
                    b.classList.toggle('is-selected', on);
                    b.setAttribute('aria-pressed', on ? 'true' : 'false');
                }
            }
            this.renderTypeFields();
        },

        // ─── Staggered on-scroll entrance reveals ───
        // Observe every [data-reveal] block within the modal's scroll container.
        // When a block crosses into view, add .is-in (CSS transitions transform +
        // opacity). Stagger via a per-block --rev-i transition-delay. Blocks already
        // visible on open cascade in immediately. GPU-only; reduced-motion shows all
        // instantly. Observer is rebuilt each render() and disconnected on close.
        setupReveals: function (scrollEl) {
            var self = this;
            if (this.revealObserver) { this.revealObserver.disconnect(); this.revealObserver = null; }

            // Collect the content blocks to reveal, assign stagger index.
            var blocks = scrollEl.querySelectorAll(
                '.gs-schedule-title, .gs-schedule-types-block, .gs-dtp, .gs-schedule-block, .gs-schedule-guest > label, .gs-schedule-meta > label, .gs-schedule-submit-block'
            );
            for (var i = 0; i < blocks.length; i++) {
                blocks[i].setAttribute('data-reveal', '');
                blocks[i].style.setProperty('--rev-i', String(i % 8)); // cap delay growth
            }

            // Reduced-motion or no IntersectionObserver → reveal everything instantly.
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reduce || typeof IntersectionObserver === 'undefined') {
                for (var j = 0; j < blocks.length; j++) { blocks[j].classList.add('is-in'); }
                return;
            }

            this.revealObserver = new IntersectionObserver(function (entries) {
                for (var e = 0; e < entries.length; e++) {
                    if (entries[e].isIntersecting) {
                        entries[e].target.classList.add('is-in');
                        self.revealObserver.unobserve(entries[e].target);
                    }
                }
            }, { root: scrollEl, threshold: 0.08, rootMargin: '0px 0px -5% 0px' });

            for (var k = 0; k < blocks.length; k++) { this.revealObserver.observe(blocks[k]); }
        },

        renderTypeFields: function () {
            if (!this.metaContainer) { return; }
            this.metaContainer.innerHTML = '';

            if (this.state.type === 'in_person') {
                this.metaContainer.appendChild(ce('label', {}, [
                    ce('span',  { text: 'Address' }),
                    ce('input', { type: 'text', name: 'meta_address', maxlength: '300', required: 'required' })
                ]));
            } else if (this.state.type === 'phone') {
                this.metaContainer.appendChild(ce('label', {}, [
                    ce('span',  { text: 'Phone number' }),
                    ce('input', { type: 'tel', name: 'meta_phone', required: 'required' })
                ]));
                var dirSel = ce('select', { name: 'meta_direction' });
                dirSel.appendChild(ce('option', { value: 'guest_calls_host', text: 'They call me' }));
                dirSel.appendChild(ce('option', { value: 'host_calls_guest', text: 'I call them' }));
                this.metaContainer.appendChild(ce('label', {}, [ce('span', { text: 'Call direction' }), dirSel]));
            } else { // video
                var provSel = ce('select', { name: 'meta_provider' });
                var providers = [
                    { v: 'gend',  l: 'Native gend video' },
                    { v: 'zoom',  l: 'Zoom' },
                    { v: 'meet',  l: 'Google Meet' },
                    { v: 'teams', l: 'Microsoft Teams' },
                    { v: 'webex', l: 'Webex' }
                ];
                for (var pi = 0; pi < providers.length; pi++) {
                    provSel.appendChild(ce('option', { value: providers[pi].v, text: providers[pi].l }));
                }
                this.metaContainer.appendChild(ce('label', {}, [ce('span', { text: 'Video provider' }), provSel]));
                this.metaContainer.appendChild(ce('label', {}, [
                    ce('span',  { text: 'Room URL (leave empty for gend native)' }),
                    ce('input', { type: 'url', name: 'meta_url', placeholder: 'https://zoom.us/j/...' })
                ]));
            }
        },

        // ───────────────────────── Visual date+time picker ─────────────────────────

        // Parse a 'YYYY-MM-DDTHH:MM' local string into { date, hour, minute }.
        // Returns null on anything malformed.
        parsePrefill: function (s) {
            if (typeof s !== 'string') { return null; }
            var m = s.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
            if (!m) { return null; }
            var y = +m[1], mo = +m[2] - 1, d = +m[3], h = +m[4], mi = +m[5];
            var dt = new Date(y, mo, d, 0, 0, 0, 0);
            if (isNaN(dt.getTime())) { return null; }
            return { date: dt, hour: h, minute: mi };
        },

        pad2: function (n) { return (n < 10 ? '0' : '') + n; },

        // Local midnight for a Date (drops time component) — used for past-day compare.
        atMidnight: function (dt) {
            return new Date(dt.getFullYear(), dt.getMonth(), dt.getDate(), 0, 0, 0, 0);
        },

        // Snap an arbitrary minute to the nearest MINUTE_STEP (clamped 0..55).
        snapMinute: function (mi) {
            var step = this.MINUTE_STEP;
            var s = Math.round(mi / step) * step;
            if (s >= 60) { s = 60 - step; }
            if (s < 0) { s = 0; }
            return s;
        },

        // Build the whole picker UI and seed its state. Returns the wrapper element.
        buildPicker: function () {
            var self = this;
            var now = new Date();

            // Seed selection from prefill, else default to TODAY + next rounded half-hour.
            var seed = self.parsePrefill(self.state.prefillStart);
            var selDate, hour, minute;
            if (seed) {
                selDate = self.atMidnight(seed.date);
                hour = seed.hour;
                minute = self.snapMinute(seed.minute);
            } else {
                selDate = self.atMidnight(now);
                // Next rounded half-hour from now (e.g. 14:12 → 14:30, 14:46 → 15:00).
                var roundedMin = now.getMinutes() <= 30 ? 30 : 0;
                hour = roundedMin === 0 ? (now.getHours() + 1) % 24 : now.getHours();
                minute = roundedMin;
            }

            self.pick = {
                selDate: selDate,
                viewYear: selDate.getFullYear(),
                viewMonth: selDate.getMonth(),
                hour: hour,
                minute: minute,
                hiddenInput: null,
                gridEl: null,
                titleEl: null,
                readoutEl: null,
                clockEl: null,
                errEl: null
            };

            // Hidden field the submit handler reads — CONTRACT: name + format unchanged.
            var hidden = ce('input', { type: 'hidden', name: 'slot_start_local' });
            self.pick.hiddenInput = hidden;

            // ── Calendar column ──
            var calTitle = ce('div', { className: 'gs-dtp-cal-title' });
            self.pick.titleEl = calTitle;
            var prevBtn = ce('button', { type: 'button', className: 'gs-dtp-nav', 'aria-label': 'Previous month',
                                         text: '‹', onclick: function () { self.shiftMonth(-1); } });
            var nextBtn = ce('button', { type: 'button', className: 'gs-dtp-nav', 'aria-label': 'Next month',
                                         text: '›', onclick: function () { self.shiftMonth(1); } });
            var calHeader = ce('div', { className: 'gs-dtp-cal-header' }, [prevBtn, calTitle, nextBtn]);

            var dow = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            var weekRow = ce('div', { className: 'gs-dtp-weekdays' });
            for (var w = 0; w < 7; w++) { weekRow.appendChild(ce('span', { text: dow[w] })); }

            var grid = ce('div', { className: 'gs-dtp-grid' });
            self.pick.gridEl = grid;

            var calCol = ce('div', { className: 'gs-dtp-cal' }, [calHeader, weekRow, grid]);

            // ── Clock / time column ──
            var readout = ce('div', { className: 'gs-dtp-readout', 'aria-live': 'polite' });
            self.pick.readoutEl = readout;

            // Analog clock face (read-only visual SVG).
            var clockWrap = ce('div', { className: 'gs-dtp-clock' });
            clockWrap.innerHTML = self.clockSvg();
            self.pick.clockEl = clockWrap;

            // Hour stepper.
            var hourMinus = ce('button', { type: 'button', className: 'gs-dtp-step', 'aria-label': 'Earlier hour',
                                           text: '−', onclick: function () { self.bumpHour(-1); } });
            var hourPlus = ce('button', { type: 'button', className: 'gs-dtp-step', 'aria-label': 'Later hour',
                                          text: '+', onclick: function () { self.bumpHour(1); } });
            var hourLabel = ce('span', { className: 'gs-dtp-step-label', text: 'Hour' });
            var hourRow = ce('div', { className: 'gs-dtp-stepper' }, [hourLabel, hourMinus, hourPlus]);

            // Minute stepper (snaps to MINUTE_STEP).
            var minMinus = ce('button', { type: 'button', className: 'gs-dtp-step', 'aria-label': 'Fewer minutes',
                                          text: '−', onclick: function () { self.bumpMinute(-1); } });
            var minPlus = ce('button', { type: 'button', className: 'gs-dtp-step', 'aria-label': 'More minutes',
                                         text: '+', onclick: function () { self.bumpMinute(1); } });
            var minLabel = ce('span', { className: 'gs-dtp-step-label', text: 'Minute' });
            var minRow = ce('div', { className: 'gs-dtp-stepper' }, [minLabel, minMinus, minPlus]);

            // Quick-picks row — one-tap common times.
            var quick = ce('div', { className: 'gs-dtp-quick' });
            var quickTimes = [[9, 0], [12, 0], [14, 0], [16, 0]];
            for (var q = 0; q < quickTimes.length; q++) {
                (function (h, mi) {
                    quick.appendChild(ce('button', {
                        type: 'button', className: 'gs-dtp-quick-btn',
                        text: self.pad2(h) + ':' + self.pad2(mi),
                        onclick: function () { self.pick.hour = h; self.pick.minute = mi; self.paintTime(); self.syncHidden(); }
                    }));
                })(quickTimes[q][0], quickTimes[q][1]);
            }

            var timeCol = ce('div', { className: 'gs-dtp-time' }, [
                readout, clockWrap, hourRow, minRow,
                ce('div', { className: 'gs-dtp-quick-label', text: 'Quick picks' }),
                quick
            ]);

            var body = ce('div', { className: 'gs-dtp-body' }, [calCol, timeCol]);

            var errEl = ce('div', { className: 'gs-dtp-err', role: 'alert' });
            self.pick.errEl = errEl;

            var wrap = ce('div', { className: 'gs-dtp' }, [
                ce('span', { className: 'gs-dtp-label', text: 'When' }),
                ce('span', { className: 'gs-schedule-hint', text: 'Pick a date, then a time.' }),
                body, hidden, errEl
            ]);

            // Initial paint.
            self.paintCalendar();
            self.paintTime();
            self.syncHidden();
            return wrap;
        },

        // SVG analog clock face skeleton (hands are positioned in paintTime via CSS transform).
        clockSvg: function () {
            var ticks = '';
            for (var i = 0; i < 12; i++) {
                var a = (i / 12) * 2 * Math.PI;
                var x1 = 50 + Math.sin(a) * 42, y1 = 50 - Math.cos(a) * 42;
                var x2 = 50 + Math.sin(a) * 46, y2 = 50 - Math.cos(a) * 46;
                ticks += '<line x1="' + x1.toFixed(1) + '" y1="' + y1.toFixed(1) + '" x2="' + x2.toFixed(1) +
                         '" y2="' + y2.toFixed(1) + '" class="gs-dtp-tick"/>';
            }
            return '<svg viewBox="0 0 100 100" class="gs-dtp-clock-svg" aria-hidden="true">' +
                   '<circle cx="50" cy="50" r="47" class="gs-dtp-clock-face"/>' + ticks +
                   '<line x1="50" y1="50" x2="50" y2="26" class="gs-dtp-hand gs-dtp-hand-hour"/>' +
                   '<line x1="50" y1="50" x2="50" y2="14" class="gs-dtp-hand gs-dtp-hand-min"/>' +
                   '<circle cx="50" cy="50" r="3" class="gs-dtp-clock-pin"/></svg>';
        },

        shiftMonth: function (delta) {
            var p = this.pick;
            var d = new Date(p.viewYear, p.viewMonth + delta, 1);
            p.viewYear = d.getFullYear();
            p.viewMonth = d.getMonth();
            this.paintCalendar();
        },

        bumpHour: function (delta) {
            var p = this.pick;
            p.hour = (p.hour + delta + 24) % 24;
            this.paintTime();
            this.syncHidden();
        },

        bumpMinute: function (delta) {
            var p = this.pick;
            var next = p.minute + delta * this.MINUTE_STEP;
            if (next >= 60) { next = 0; this.bumpHour(0); p.hour = (p.hour + 1) % 24; }
            else if (next < 0) { next = 60 - this.MINUTE_STEP; p.hour = (p.hour + 23) % 24; }
            p.minute = next;
            this.paintTime();
            this.syncHidden();
        },

        // Repaint the day grid (month title, weekday cells, selected/today/past states).
        paintCalendar: function () {
            var self = this, p = this.pick;
            var months = ['January', 'February', 'March', 'April', 'May', 'June',
                          'July', 'August', 'September', 'October', 'November', 'December'];
            p.titleEl.textContent = months[p.viewMonth] + ' ' + p.viewYear;

            var grid = p.gridEl;
            grid.innerHTML = '';

            var first = new Date(p.viewYear, p.viewMonth, 1);
            var lead = first.getDay(); // 0=Sun
            var daysInMonth = new Date(p.viewYear, p.viewMonth + 1, 0).getDate();
            var todayMid = self.atMidnight(new Date());
            var selMid = p.selDate ? p.selDate.getTime() : -1;

            // Leading blanks for alignment.
            for (var b = 0; b < lead; b++) {
                grid.appendChild(ce('span', { className: 'gs-dtp-day gs-dtp-day-blank' }));
            }

            for (var d = 1; d <= daysInMonth; d++) {
                (function (day) {
                    var cellDate = new Date(p.viewYear, p.viewMonth, day, 0, 0, 0, 0);
                    var isPast = cellDate.getTime() < todayMid.getTime();
                    var cls = 'gs-dtp-day';
                    if (cellDate.getTime() === todayMid.getTime()) { cls += ' gs-dtp-day-today'; }
                    if (selMid === cellDate.getTime()) { cls += ' gs-dtp-day-sel'; }
                    if (isPast) { cls += ' gs-dtp-day-past'; }

                    var cell = ce('button', {
                        type: 'button',
                        className: cls,
                        text: String(day),
                        'data-i': String(day) // used only for stagger delay
                    });
                    if (isPast) {
                        cell.setAttribute('disabled', 'disabled');
                        cell.setAttribute('aria-disabled', 'true');
                    } else {
                        cell.addEventListener('click', function () {
                            p.selDate = cellDate;
                            self.paintCalendar();
                            self.syncHidden();
                        });
                    }
                    // Staggered entrance delay (CSS animation; honors reduced-motion).
                    cell.style.setProperty('--gs-dtp-i', String(day));
                    grid.appendChild(cell);
                })(d);
            }
        },

        // Repaint the digital readout + analog hands from pick.hour/minute.
        paintTime: function () {
            var p = this.pick;
            p.readoutEl.textContent = this.pad2(p.hour) + ':' + this.pad2(p.minute);
            var hourHand = p.clockEl.querySelector('.gs-dtp-hand-hour');
            var minHand = p.clockEl.querySelector('.gs-dtp-hand-min');
            if (hourHand && minHand) {
                var hourDeg = ((p.hour % 12) + p.minute / 60) * 30; // 360/12
                var minDeg = p.minute * 6;                          // 360/60
                hourHand.setAttribute('transform', 'rotate(' + hourDeg.toFixed(1) + ' 50 50)');
                minHand.setAttribute('transform', 'rotate(' + minDeg.toFixed(1) + ' 50 50)');
            }
        },

        // Write the combined selection into the hidden slot_start_local field.
        // Format MUST be 'YYYY-MM-DDTHH:MM' local (no Z) — the submit contract.
        syncHidden: function () {
            var p = this.pick;
            if (!p || !p.hiddenInput) { return; }
            if (!p.selDate) { p.hiddenInput.value = ''; return; }
            var v = p.selDate.getFullYear() + '-' + this.pad2(p.selDate.getMonth() + 1) + '-' +
                    this.pad2(p.selDate.getDate()) + 'T' + this.pad2(p.hour) + ':' + this.pad2(p.minute);
            p.hiddenInput.value = v;
            if (p.errEl) { p.errEl.textContent = ''; }
        },

        onSubmit: function (e, form, errBox) {
            e.preventDefault();
            errBox.textContent = '';

            var fd = new FormData(form);

            // Convert local datetime to UTC ISO Z (strip ms).
            var slotLocal = fd.get('slot_start_local');
            if (!slotLocal) {
                var msg = 'Pick a date and time for the meeting';
                if (this.pick && this.pick.errEl) { this.pick.errEl.textContent = msg; }
                errBox.textContent = msg;
                return;
            }
            var dt = new Date(slotLocal);
            if (isNaN(dt.getTime())) { errBox.textContent = 'Invalid slot start'; return; }
            var slotUtcIso = dt.toISOString().replace(/\.\d{3}Z$/, 'Z');

            var meta = {};
            if (this.state.type === 'in_person') {
                meta.address = fd.get('meta_address') || '';
            } else if (this.state.type === 'phone') {
                meta.callee_number = fd.get('meta_phone')     || '';
                meta.direction     = fd.get('meta_direction') || 'guest_calls_host';
            } else { // video
                meta.provider = fd.get('meta_provider') || 'gend';
                var u = fd.get('meta_url');
                if (u) { meta.room_url = u; }
            }

            // POST body matches the authed REST contract — keys are required by
            // Gend_GS_Booking_Meetings_REST::handle_create (slot_start_utc + meeting_type + meeting_meta).
            var body = {
                slot_start_utc: slotUtcIso,
                duration_min:   this.state.duration,
                meeting_type:   this.state.type,
                meeting_meta:   meta,
                guest_name:     fd.get('guest_name')  || '',
                guest_email:    fd.get('guest_email') || '',
                guest_phone:    fd.get('guest_phone') || ''
            };

            var self = this;
            fetch(gsScheduleData.restUrl + 'calendar/meetings', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   gsScheduleData.nonce
                },
                body: JSON.stringify(body)
            }).then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) {
                        errBox.textContent = (data && (data.message || data.code)) || 'Failed to create meeting';
                        return;
                    }
                    window.dispatchEvent(new CustomEvent('gs:meeting-created', { detail: data }));
                    self.closeModal();
                });
            }).catch(function (err) {
                errBox.textContent = 'Network error: ' + (err && err.message ? err.message : 'unknown');
            });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { ScheduleMeeting.mount(); });
    } else {
        ScheduleMeeting.mount();
    }

    // Document-level delegate: any element with [data-gs-schedule-open] opens the
    // same modal as the profile-icon trigger (e.g. the on-calendar "New Meeting"
    // button rendered server-side in member-calendar.php). Guard mount() so the
    // modalRoot exists even if the element is clicked before/independent of mount.
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-gs-schedule-open]') : null;
        if (!t) { return; }
        e.preventDefault();
        if (!ScheduleMeeting.modalRoot) { ScheduleMeeting.mount(); }
        ScheduleMeeting.openModal();
    });

    // Calendar-as-picker: the member calendar dispatches gs:schedule-open with a
    // { start: 'YYYY-MM-DDTHH:MM' } local datetime when a day cell / time slot is
    // clicked. Open the modal pre-filled with that slot start. Ensure mount() ran
    // so modalRoot exists even if the calendar is clicked before the trigger.
    document.addEventListener('gs:schedule-open', function (e) {
        if (!ScheduleMeeting.modalRoot) { ScheduleMeeting.mount(); }
        ScheduleMeeting.openModal(e && e.detail && e.detail.start);
    });
})();
