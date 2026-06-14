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
 *   - Slot datetime picker (datetime-local → UTC ISO Z at submit)
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
        state: { open: false, type: 'video', duration: 30, mounted: false },
        modalRoot: null,
        metaContainer: null,

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

        openModal: function () {
            this.state.open = true;
            this.render();
            this.modalRoot.setAttribute('aria-hidden', 'false');
        },

        closeModal: function () {
            this.state.open = false;
            this.modalRoot.setAttribute('aria-hidden', 'true');
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

            // ─── Meeting-type radio group ───
            var typeFieldset = ce('fieldset', { className: 'gs-schedule-types' });
            typeFieldset.appendChild(ce('legend', { text: 'Meeting type' }));
            var types = [
                { value: 'in_person', label: 'In person' },
                { value: 'phone',     label: 'Phone' },
                { value: 'video',     label: 'Video' }
            ];
            for (var ti = 0; ti < types.length; ti++) {
                (function (t) {
                    var id = 'gs-type-' + t.value;
                    var radio = ce('input', {
                        type: 'radio', name: 'gs-type', id: id, value: t.value,
                        onchange: function () { self.state.type = t.value; self.renderTypeFields(); }
                    });
                    if (t.value === self.state.type) { radio.setAttribute('checked', 'checked'); }
                    typeFieldset.appendChild(ce('label', { 'for': id }, [radio, ' ' + t.label]));
                })(types[ti]);
            }

            // ─── Duration picker from named_durations (BOOK-08) ───
            var durSel = ce('select', { className: 'gs-schedule-duration', name: 'duration_min',
                                        onchange: function (e) { self.state.duration = parseInt(e.target.value, 10) || 30; } });
            for (var di = 0; di < named.length; di++) {
                var d = named[di];
                var opt = ce('option', { value: String(d.minutes), text: d.label + ' (' + d.minutes + ' min)' });
                if (d.minutes === self.state.duration) { opt.setAttribute('selected', 'selected'); }
                durSel.appendChild(opt);
            }

            // ─── Slot datetime picker ───
            var slotField = ce('label', {}, [
                ce('span', { text: 'Slot start' }),
                ce('input', { type: 'datetime-local', name: 'slot_start_local', required: 'required' })
            ]);

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

            var form = ce('form', { className: 'gs-schedule-form',
                                    onsubmit: function (e) { self.onSubmit(e, form, errBox); } },
                [
                    typeFieldset, slotField,
                    ce('label', {}, [ce('span', { text: 'Duration' }), durSel]),
                    guestFields,
                    this.metaContainer,
                    submitBtn,
                    errBox
                ]);

            this.modalRoot.innerHTML = '';
            this.modalRoot.appendChild(ce('div', { className: 'gs-schedule-card' }, [closeBtn, title, form]));
            this.renderTypeFields();
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

        onSubmit: function (e, form, errBox) {
            e.preventDefault();
            errBox.textContent = '';

            var fd = new FormData(form);

            // Convert local datetime to UTC ISO Z (strip ms).
            var slotLocal = fd.get('slot_start_local');
            if (!slotLocal) { errBox.textContent = 'Pick a slot start'; return; }
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
})();
