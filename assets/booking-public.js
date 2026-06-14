/**
 * Phase 29-04 — Public booking page controller (vanilla JS, no frameworks).
 *
 * Hydrates from window.gsBookingData (inlined by Gend_GS_Booking_Public_Page).
 * Auto-detects viewer tz via Intl.DateTimeFormat().resolvedOptions().timeZone.
 *
 * Surfaces:
 *   - Duration picker (named durations from host's booking_settings_json)
 *   - Meeting-type selector (only types the host enabled)
 *   - Slot grid (fetched from /gs/v1/calendar/public/{share_token}/slots)
 *     rendered in BOTH viewer-tz AND host-tz so the bookee sees both
 *   - Booking form (name/email/phone/notes + per-type meta + honeypot hp_email)
 *   - On 409 slot_taken → auto re-fetch slots + clear pick + alert
 *   - On 200 → confirmation card with cancel link
 *
 * Honeypot hp_email rendered in DOM, CSS-hidden via .gs-honeypot, tabindex=-1
 * so humans never fill it and screen readers skip; bots that auto-fill all
 * inputs trigger Plan 29-01's fake-200 silent-reject path server-side.
 *
 * Public REST routes called (all from Plan 29-01):
 *   - GET  {restUrl}calendar/public/{share_token}/slots?from=&to=&duration_min=
 *   - POST {restUrl}calendar/public/{share_token}/book
 */
(function () {
  'use strict';

  if (typeof window.gsBookingData === 'undefined') {
    // Bootstrap data missing — bail silently, the inline payload should always
    // be present in the rendered HTML.
    return;
  }
  var D = window.gsBookingData;

  var viewerTz = 'UTC';
  try {
    if (typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
      viewerTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    }
  } catch (e) { viewerTz = 'UTC'; }

  // ── Tiny DOM helper ────────────────────────────────────────────────────
  function ce(tag, attrs, kids) {
    var el = document.createElement(tag);
    attrs = attrs || {};
    for (var k in attrs) {
      if (!Object.prototype.hasOwnProperty.call(attrs, k)) continue;
      if (k === 'className') el.className = attrs[k];
      else if (k === 'text') el.textContent = attrs[k];
      else if (k === 'html') el.innerHTML = attrs[k];
      else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') el.addEventListener(k.substring(2).toLowerCase(), attrs[k]);
      else el.setAttribute(k, attrs[k]);
    }
    kids = Array.isArray(kids) ? kids : (kids ? [kids] : []);
    for (var i = 0; i < kids.length; i++) {
      var kid = kids[i];
      if (kid == null) continue;
      el.appendChild(typeof kid === 'string' ? document.createTextNode(kid) : kid);
    }
    return el;
  }

  function fmtSlot(iso, tz) {
    try {
      var dt = new Date(iso);
      return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: tz,
      }).format(dt);
    } catch (e) {
      return iso;
    }
  }

  function isoZNoMs(d) {
    // 2026-06-14T13:30:00Z (no milliseconds suffix the slot REST is picky about)
    return d.toISOString().replace(/\.\d{3}Z$/, 'Z');
  }

  // ── App ───────────────────────────────────────────────────────────────
  var App = {
    state: {
      duration: (D.namedDurations && D.namedDurations[0] && D.namedDurations[0].minutes) || 30,
      type: (D.meetingTypes && D.meetingTypes[0] && D.meetingTypes[0].value) || 'video',
      slots: [],
      pickedSlot: null,
      loading: false,
      error: '',
      confirmed: null,
    },

    mount: function () {
      this.root = document.getElementById('gs-booking-root');
      if (!this.root) return;
      this.render();
      this.loadSlots();
    },

    loadSlots: function () {
      var self = this;
      self.state.loading = true;
      self.state.error = '';
      self.render();

      var from = new Date();
      from.setUTCHours(0, 0, 0, 0);
      var to = new Date(from.getTime() + 14 * 86400 * 1000); // 14-day window
      var fromIso = isoZNoMs(from);
      var toIso = isoZNoMs(to);
      var url = D.restUrl + 'calendar/public/' + encodeURIComponent(D.shareToken)
        + '/slots?from=' + encodeURIComponent(fromIso)
        + '&to=' + encodeURIComponent(toIso)
        + '&duration_min=' + encodeURIComponent(self.state.duration);

      fetch(url, { credentials: 'omit' })
        .then(function (res) { return res.json().then(function (j) { return { status: res.status, ok: res.ok, body: j }; }); })
        .then(function (r) {
          if (!r.ok) throw new Error((r.body && (r.body.message || r.body.code)) || 'Failed to load slots');
          self.state.slots = (r.body && r.body.slots) || [];
        })
        .catch(function (err) { self.state.error = err.message || 'Failed to load slots'; })
        .then(function () { self.state.loading = false; self.render(); });
    },

    pickSlot: function (slot) {
      this.state.pickedSlot = slot;
      this.render();
    },

    submitBooking: function (form) {
      var self = this;
      if (!self.state.pickedSlot) { alert('Pick a slot first'); return; }
      var fd = new FormData(form);
      var meta = {};
      if (self.state.type === 'in_person') {
        meta.address = fd.get('meta_address') || '';
      }
      if (self.state.type === 'phone') {
        meta.callee_number = fd.get('meta_phone') || '';
        meta.direction = fd.get('meta_direction') || 'guest_calls_host';
      }
      if (self.state.type === 'video') {
        meta.provider = 'gend'; // Public bookee uses host's default video provider.
      }
      var body = {
        slot_start_utc: self.state.pickedSlot.start_utc,
        duration_min: self.state.duration,
        meeting_type: self.state.type,
        meeting_meta: meta,
        guest_name: fd.get('guest_name') || '',
        guest_email: fd.get('guest_email') || '',
        guest_phone: fd.get('guest_phone') || '',
        notes: fd.get('notes') || '',
        hp_email: fd.get('hp_email') || '', // HONEYPOT — humans never fill.
      };

      var url = D.restUrl + 'calendar/public/' + encodeURIComponent(D.shareToken) + '/book';
      fetch(url, {
        method: 'POST',
        credentials: 'omit',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      })
        .then(function (res) { return res.json().then(function (j) { return { status: res.status, ok: res.ok, body: j }; }); })
        .then(function (r) {
          if (r.status === 409 && r.body && r.body.code === 'slot_taken') {
            alert('That slot was just taken — refreshing open slots.');
            self.state.pickedSlot = null;
            self.loadSlots();
            return;
          }
          if (!r.ok) {
            alert((r.body && (r.body.message || r.body.code)) || 'Booking failed');
            return;
          }
          self.state.confirmed = r.body;
          self.render();
        })
        .catch(function (err) { alert('Network error: ' + (err.message || err)); });
    },

    render: function () {
      if (this.state.confirmed) { this.renderConfirmed(); return; }
      this.root.innerHTML = '';

      var card = ce('div', { className: 'gs-booking-card' });
      card.appendChild(ce('h1', { text: 'Book a meeting with ' + D.hostName }));
      card.appendChild(ce('p', {
        className: 'gs-tz-line',
        text: 'Host is in ' + D.memberTz + ' • You are in ' + viewerTz,
      }));

      // Duration picker
      var self = this;
      var durSel = ce('select', {
        onchange: function (e) {
          self.state.duration = parseInt(e.target.value, 10);
          self.state.pickedSlot = null;
          self.loadSlots();
        },
      });
      (D.namedDurations || []).forEach(function (d) {
        var opt = ce('option', { value: String(d.minutes), text: d.label + ' (' + d.minutes + ' min)' });
        if (d.minutes === self.state.duration) opt.setAttribute('selected', 'selected');
        durSel.appendChild(opt);
      });
      card.appendChild(ce('label', {}, [ce('span', { text: 'Duration' }), durSel]));

      // Meeting type picker
      var typeSel = ce('select', {
        onchange: function (e) {
          self.state.type = e.target.value;
          self.render();
        },
      });
      (D.meetingTypes || []).forEach(function (t) {
        var opt = ce('option', { value: t.value, text: t.label });
        if (t.value === self.state.type) opt.setAttribute('selected', 'selected');
        typeSel.appendChild(opt);
      });
      card.appendChild(ce('label', {}, [ce('span', { text: 'Meeting type' }), typeSel]));

      // Slot grid
      var slotGrid = ce('div', { className: 'gs-slot-grid' });
      if (this.state.loading) {
        slotGrid.appendChild(ce('p', { text: 'Loading slots...' }));
      } else if (this.state.error) {
        slotGrid.appendChild(ce('p', { className: 'gs-error', text: this.state.error }));
      } else if (!this.state.slots.length) {
        slotGrid.appendChild(ce('p', { text: 'No open slots in the next 14 days. Try a different duration.' }));
      } else {
        this.state.slots.forEach(function (slot) {
          var isPicked = self.state.pickedSlot && self.state.pickedSlot.start_utc === slot.start_utc;
          var btn = ce('button', {
            type: 'button',
            className: 'gs-slot' + (isPicked ? ' gs-slot-picked' : ''),
            onclick: function () { self.pickSlot(slot); },
            text: fmtSlot(slot.start_utc, viewerTz) + ' (host: ' + fmtSlot(slot.start_utc, D.memberTz) + ')',
          });
          slotGrid.appendChild(btn);
        });
      }
      card.appendChild(slotGrid);

      // Guest form (only after a slot is picked)
      if (this.state.pickedSlot) {
        var form = ce('form', {
          className: 'gs-booking-form',
          onsubmit: function (e) { e.preventDefault(); self.submitBooking(e.target); },
        });
        form.appendChild(ce('label', {}, [
          ce('span', { text: 'Your name' }),
          ce('input', { type: 'text', name: 'guest_name', maxlength: '120', required: 'required' }),
        ]));
        form.appendChild(ce('label', {}, [
          ce('span', { text: 'Your email' }),
          ce('input', { type: 'email', name: 'guest_email', required: 'required' }),
        ]));
        form.appendChild(ce('label', {}, [
          ce('span', { text: 'Phone (optional)' }),
          ce('input', { type: 'tel', name: 'guest_phone' }),
        ]));
        if (this.state.type === 'in_person') {
          form.appendChild(ce('label', {}, [
            ce('span', { text: 'Address (for the host to confirm)' }),
            ce('input', { type: 'text', name: 'meta_address' }),
          ]));
        }
        if (this.state.type === 'phone') {
          form.appendChild(ce('label', {}, [
            ce('span', { text: 'Phone number for the call' }),
            ce('input', { type: 'tel', name: 'meta_phone', required: 'required' }),
          ]));
          var dirSel = ce('select', { name: 'meta_direction' });
          [
            { v: 'guest_calls_host', l: 'I will call them' },
            { v: 'host_calls_guest', l: 'They will call me' },
          ].forEach(function (o) { dirSel.appendChild(ce('option', { value: o.v, text: o.l })); });
          form.appendChild(ce('label', {}, [ce('span', { text: 'Call direction' }), dirSel]));
        }
        form.appendChild(ce('label', {}, [
          ce('span', { text: 'Notes (optional)' }),
          ce('textarea', { name: 'notes', maxlength: '500' }),
        ]));

        // HONEYPOT — visually hidden via .gs-honeypot, never tab-reachable,
        // always present in DOM for bots that auto-fill every input field.
        var hp = ce('label', { className: 'gs-honeypot', 'aria-hidden': 'true' }, [
          ce('span', { text: 'Leave this field empty' }),
          ce('input', { type: 'text', name: 'hp_email', autocomplete: 'off', tabindex: '-1' }),
        ]);
        form.appendChild(hp);

        form.appendChild(ce('button', {
          type: 'submit',
          className: 'gs-booking-submit',
          text: 'Confirm booking',
        }));
        card.appendChild(form);
      }

      this.root.appendChild(card);
    },

    renderConfirmed: function () {
      this.root.innerHTML = '';
      var c = this.state.confirmed || {};
      var startIso = c.starts_at_utc || (this.state.pickedSlot && this.state.pickedSlot.start_utc) || '';

      var card = ce('div', { className: 'gs-booking-card gs-booking-confirmed' });
      card.appendChild(ce('h1', { text: 'Booking confirmed' }));
      if (startIso) {
        card.appendChild(ce('p', {
          text: 'When: ' + fmtSlot(startIso, viewerTz) + ' (your time) / ' + fmtSlot(startIso, D.memberTz) + ' (host time)',
        }));
      }
      if (c.meeting_id) {
        card.appendChild(ce('p', { text: 'Reference: meeting #' + c.meeting_id }));
      }
      if (c.cancellation_token) {
        var cancelHref = D.restUrl + 'calendar/public/meeting/' + encodeURIComponent(c.cancellation_token);
        card.appendChild(ce('p', {}, [
          ce('a', { href: cancelHref, target: '_blank', rel: 'noopener', text: 'View / cancel this booking' }),
        ]));
      }
      card.appendChild(ce('p', { className: 'gs-tz-line', text: 'You’ll receive a confirmation email with full meeting details.' }));
      this.root.appendChild(card);
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { App.mount(); });
  } else {
    App.mount();
  }
})();
