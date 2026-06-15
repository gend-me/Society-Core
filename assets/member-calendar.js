/* =============================================================================
 * GenD Society — Member Calendar (interactive glass grid)
 *
 * Phase 26 Plan 02 — the bespoke vanilla-JS Month/Week/Day renderer that mounts
 * into #gs-calendar-app. No FullCalendar / TOAST UI / React / CDN deps — native
 * Date math + Intl.DateTimeFormat (configured timezone) only.
 *
 *   - Month / Week / Day views + prev / next / today navigation
 *   - Staggered per-cell "builds-itself" reveal (transform/opacity only)
 *   - Color-coded source layers with show/hide legend toggles
 *   - Click-to-open glassmorphic event detail popover (Esc / outside closes)
 *   - Distinct all-day vs timed event chips (CAL-05)
 *   - Every label rendered in #gs-calendar-app[data-tz] (CAL-08)
 *
 * Events are MOCK this phase, shaped EXACTLY to the locked Phase 27 contract
 * below — Phase 27's gs/v1/calendar/events REST drops in unchanged.
 * ========================================================================== */

(function () {
	'use strict';

	/**
	 * Calendar event — LOCKED normalized shape.
	 * Phase 27 (gs/v1/calendar/events contract — do not change shape):
	 * {
	 *   id:        string,                                   // stable unique id
	 *   source:    'projects' | 'campaigns' | 'meetings',    // origin subsystem
	 *   type:      string,                                   // source-specific kind
	 *   title:     string,
	 *   start:     ISO8601-string,                           // event start
	 *   end:       ISO8601-string | null,                    // null => point-in-time
	 *   all_day:   boolean,
	 *   color:     string,                                   // hex; defaults to source color
	 *   status:    string,                                   // source-specific status
	 *   url:       string | null,                            // deep-link or null
	 *   busy:      boolean                                   // blocks availability
	 * }
	 */

	/** Source registry — label + accent color per event source. */
	var GS_CAL_SOURCES = {
		projects:  { label: 'Projects',  color: '#89C2E0' },
		campaigns: { label: 'Campaigns', color: '#b608c9' },
		meetings:  { label: 'Meetings',  color: '#00ff88' }
	};

	var DAY_MS = 86400000;

	/* The gs/v1/calendar/events adapters tag rows with the short source codes
	 * pm/bm/mtg. The renderer (GS_CAL_SOURCES, legend toggles, eventsForDay filter)
	 * keys on the long names projects/campaigns/meetings. Map short→long on ingest
	 * so legend show/hide + source swatch resolve — without touching the event shape
	 * or any render function. Long names pass through unchanged. */
	var GS_SOURCE_ALIAS = {
		pm: 'projects',  projects:  'projects',
		bm: 'campaigns', campaigns: 'campaigns',
		mtg: 'meetings', meetings:  'meetings'
	};
	function normalizeSource(src) {
		return GS_SOURCE_ALIAS[src] || src;
	}

	/* Phase 28-03 — availability overlay cache, keyed by view|from|to. Busted by
	 * the 'gs:availability-changed' event after a Save in the settings panel. */
	var _overlayCache = { key: '', data: null };

	/* ---------------------------------------------------------------------------
	 * Timezone-aware helpers — ALL display formatting flows through Intl with the
	 * configured timeZone, never the browser's local zone.
	 * ------------------------------------------------------------------------- */

	/** Resolve configured tz from the mount root, with a sane fallback. */
	function resolveTz(root) {
		var tz = root && root.dataset ? root.dataset.tz : '';
		if (tz) { return tz; }
		try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; }
		catch (e) { return 'UTC'; }
	}

	/** Cache Intl.DateTimeFormat instances keyed by tz + option signature. */
	var _fmtCache = {};
	function fmt(tz, opts) {
		var key = tz + '|' + JSON.stringify(opts);
		if (!_fmtCache[key]) {
			_fmtCache[key] = new Intl.DateTimeFormat('en-US',
				Object.assign({ timeZone: tz }, opts));
		}
		return _fmtCache[key];
	}

	/**
	 * Decompose a Date into its Y/M/D in the configured tz (NOT browser-local).
	 * Returns { y, m (1-12), d }. Used so "today" + cell-date comparison happen
	 * in the calendar's zone (CAL-08), not the viewer's.
	 */
	function ymdInTz(date, tz) {
		var parts = fmt(tz, { year: 'numeric', month: '2-digit', day: '2-digit' })
			.formatToParts(date);
		var o = {};
		parts.forEach(function (p) { o[p.type] = p.value; });
		return { y: +o.year, m: +o.month, d: +o.day };
	}

	/** "2026-06-13" key for a Date as seen in the configured tz. */
	function dayKeyInTz(date, tz) {
		var p = ymdInTz(date, tz);
		return p.y + '-' + String(p.m).padStart(2, '0') + '-' + String(p.d).padStart(2, '0');
	}

	/* ---------------------------------------------------------------------------
	 * Phase 31 — calendar-as-picker. Clicking the empty part of a day cell (Month)
	 * or a time slot (Week/Day) opens the Schedule Meeting modal pre-filled with
	 * that date/time, via the 'gs:schedule-open' CustomEvent the modal listens for.
	 * The datetime is emitted as a LOCAL 'YYYY-MM-DDTHH:MM' string (no Z) so it
	 * drops straight into the <input type="datetime-local"> field.
	 * ------------------------------------------------------------------------- */

	/** Dispatch the schedule-open event with a local 'YYYY-MM-DDTHH:MM' start. */
	function dispatchScheduleOpen(localStart) {
		document.dispatchEvent(new CustomEvent('gs:schedule-open', {
			detail: { start: localStart }
		}));
	}

	/**
	 * Build a 'YYYY-MM-DDTHH:MM' local datetime-local string for a calendar day
	 * (as seen in the configured tz) at the given hour:minute. We take the day-key
	 * (already tz-correct) and append the chosen wall-clock time — the user picks
	 * the slot they SEE, so the wall-clock hour is exactly what they intend.
	 */
	function localDateTimeForDay(date, tz, hour, minute) {
		var key = dayKeyInTz(date, tz); // YYYY-MM-DD in the configured tz
		return key + 'T' + String(hour || 0).padStart(2, '0') + ':' +
			String(minute || 0).padStart(2, '0');
	}

	/** Formatted time, e.g. "2:30 PM", in the configured tz. */
	function timeLabel(date, tz) {
		return fmt(tz, { hour: 'numeric', minute: '2-digit' }).format(date);
	}

	/** Short weekday + day-of-month header, e.g. "Mon 13". */
	function dowDayLabel(date, tz) {
		return fmt(tz, { weekday: 'short', day: 'numeric' }).format(date);
	}

	/* ---------------------------------------------------------------------------
	 * Mock events — shaped to the LOCKED contract. Computed relative to "now" so
	 * they always land in the currently-visible month. Phase 27 replaces this
	 * function body with a REST fetch returning the identical shape.
	 * ------------------------------------------------------------------------- */
	function buildMockEvents() {
		var now = new Date();
		var y = now.getFullYear();
		var m = now.getMonth(); // 0-based local; mock anchoring is approximate by design

		// Helper: ISO string for a given day-of-month + hh:mm (local clock).
		function iso(day, hh, mm) {
			return new Date(y, m, day, hh || 0, mm || 0, 0).toISOString();
		}
		function isoDateOnly(day) {
			// all-day: midnight start, no offset semantics needed for mock display
			return new Date(y, m, day, 0, 0, 0).toISOString();
		}

		var P = GS_CAL_SOURCES.projects.color;
		var C = GS_CAL_SOURCES.campaigns.color;
		var G = GS_CAL_SOURCES.meetings.color;

		return [
			// --- Projects (blue) ---
			{ id: 'pm-task-482', source: 'projects', type: 'task',
			  title: 'Wireframe review', start: iso(3, 10, 30), end: iso(3, 11, 30),
			  all_day: false, color: P, status: 'open', url: '/app/projects/482', busy: true },
			{ id: 'pm-due-77', source: 'projects', type: 'project_due',
			  title: 'Brand kit delivery', start: isoDateOnly(12), end: null,
			  all_day: true, color: P, status: 'open', url: '/app/projects/77', busy: false },
			{ id: 'pm-ms-91', source: 'projects', type: 'milestone',
			  title: 'Beta milestone', start: isoDateOnly(20), end: null,
			  all_day: true, color: P, status: 'open', url: null, busy: false },
			{ id: 'pm-task-510', source: 'projects', type: 'task',
			  title: 'QA pass', start: iso(20, 14, 0), end: iso(20, 16, 0),
			  all_day: false, color: P, status: 'done', url: '/app/projects/510', busy: true },

			// --- Campaigns (magenta) ---
			{ id: 'bm-post-12', source: 'campaigns', type: 'social_post',
			  title: 'IG launch teaser', start: iso(8, 9, 0), end: null,
			  all_day: false, color: C, status: 'scheduled', url: '/admin.php?page=blog-manager', busy: false },
			{ id: 'bm-drip-5', source: 'campaigns', type: 'drip',
			  title: 'Welcome drip step 2', start: iso(15, 8, 0), end: null,
			  all_day: false, color: C, status: 'sent', url: null, busy: false },
			{ id: 'bm-post-19', source: 'campaigns', type: 'social_post',
			  title: 'Evergreen reshare', start: iso(24, 18, 30), end: null,
			  all_day: false, color: C, status: 'scheduled', url: '/admin.php?page=blog-manager', busy: false },

			// --- Meetings (green) ---
			{ id: 'mt-meet-301', source: 'meetings', type: 'meeting',
			  title: 'Discovery call', start: iso(5, 13, 0), end: iso(5, 14, 0),
			  all_day: false, color: G, status: 'booked', url: '/members/me/calendar', busy: true },
			{ id: 'mt-meet-318', source: 'meetings', type: 'meeting',
			  title: 'Design sync', start: iso(17, 11, 0), end: iso(17, 11, 30),
			  all_day: false, color: G, status: 'booked', url: null, busy: true },
			{ id: 'mt-meet-322', source: 'meetings', type: 'meeting',
			  title: 'Sprint retro', start: iso(28, 15, 0), end: iso(28, 16, 0),
			  all_day: false, color: G, status: 'cancelled', url: null, busy: false }
		];
	}

	/* ---------------------------------------------------------------------------
	 * Calendar controller
	 * ------------------------------------------------------------------------- */
	function Calendar(root) {
		this.root = root;
		this.tz = resolveTz(root);
		this.reduced = window.matchMedia &&
			window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		// Phase 27 wire-up: when the REST localize (gsCalendarData) is present we
		// drive the grid from live events (start empty, fetch fills it). buildMockEvents
		// remains ONLY as a dev-safety fallback when the localize is absent.
		this._hasRest = !!(window.gsCalendarData && window.gsCalendarData.restUrl);
		this.state = {
			view: 'month',
			cursor: new Date(),
			hiddenSources: {},          // { projects:true } => hidden
			events: this._hasRest ? [] : buildMockEvents()
		};
		// Track the last-fetched range so navigation within it doesn't re-hit the
		// server, and so a failed fetch degrades to whatever we already have.
		this._fetchedFrom = null;
		this._fetchedTo = null;
	}

	/* ---------------------------------------------------------------------------
	 * Phase 27 — live events fetch. GETs the gs/v1/calendar/events aggregation
	 * endpoint (cookie-authed; needs X-WP-Nonce) for [fromISO, toISO), maps the
	 * normalized rows into this.state.events (already the locked shape), then
	 * re-renders. Handles both a bare array and an {events:[...]} envelope. On any
	 * failure it leaves the current events untouched (graceful, never throws).
	 * ------------------------------------------------------------------------- */
	Calendar.prototype.fetchEvents = function (fromISO, toISO) {
		if (!this._hasRest) { return; }
		var self = this;
		var base = String(window.gsCalendarData.restUrl).replace(/\/$/, '');
		var url = base + '/calendar/events?from=' + encodeURIComponent(fromISO) +
			'&to=' + encodeURIComponent(toISO);
		var nonce = window.gsCalendarData.nonce || '';
		fetch(url, {
			credentials: 'same-origin',
			headers: nonce ? { 'X-WP-Nonce': nonce } : {}
		}).then(function (r) {
			return r.ok ? r.json() : null;
		}).then(function (data) {
			if (!data) { return; }                 // non-2xx => keep current events
			if (data.code && !Array.isArray(data)) { return; } // WP_Error envelope
			var list = Array.isArray(data) ? data
				: (data && Array.isArray(data.events) ? data.events : null);
			if (!list) { return; }                 // unexpected shape => keep current
			// Normalize source codes (pm/bm/mtg → projects/campaigns/meetings) so the
			// legend filter + color map resolve. Default missing color from the source.
			list.forEach(function (ev) {
				if (ev && ev.source) {
					ev.source = normalizeSource(ev.source);
					if (!ev.color && GS_CAL_SOURCES[ev.source]) {
						ev.color = GS_CAL_SOURCES[ev.source].color;
					}
				}
			});
			self.state.events = list;
			self._fetchedFrom = fromISO;
			self._fetchedTo = toISO;
			self.render();
		}).catch(function () { /* silent — keep current events on network error */ });
	};

	/**
	 * The wide [from, to) range to fetch for the current cursor: covers the
	 * cursor's month padded by a full month on each side, so month/week/day
	 * navigation within ±1 month re-uses the cached set without a refetch.
	 */
	Calendar.prototype._fetchRange = function () {
		var c = this.state.cursor;
		var from = new Date(c.getFullYear(), c.getMonth() - 1, 1, 0, 0, 0);
		var to = new Date(c.getFullYear(), c.getMonth() + 2, 1, 0, 0, 0);
		return { fromISO: from.toISOString(), toISO: to.toISOString(),
			fromMs: from.getTime(), toMs: to.getTime() };
	};

	/** Fetch only if the cursor moved outside the last-fetched window. */
	Calendar.prototype.ensureEvents = function (force) {
		if (!this._hasRest) { return; }
		var r = this._fetchRange();
		if (!force && this._fetchedFrom && this._fetchedTo) {
			var have = new Date(this._fetchedFrom).getTime();
			var haveTo = new Date(this._fetchedTo).getTime();
			var cursorMs = this.state.cursor.getTime();
			if (cursorMs >= have && cursorMs < haveTo) { return; } // still covered
		}
		this.fetchEvents(r.fromISO, r.toISO);
	};

	/** Visible events for the active view (filtered by hidden sources). */
	Calendar.prototype.visibleEvents = function () {
		var hidden = this.state.hiddenSources;
		return this.state.events.filter(function (ev) { return !hidden[ev.source]; });
	};

	/* ---- Navigation -------------------------------------------------------- */
	Calendar.prototype.shift = function (dir) {
		var c = new Date(this.state.cursor.getTime());
		if (this.state.view === 'month') { c.setMonth(c.getMonth() + dir); }
		else if (this.state.view === 'week') { c.setDate(c.getDate() + dir * 7); }
		else { c.setDate(c.getDate() + dir); }
		this.state.cursor = c;
		this.render();
		this.ensureEvents();
	};
	Calendar.prototype.goToday = function () {
		this.state.cursor = new Date();
		this.render();
		this.ensureEvents();
	};
	Calendar.prototype.setView = function (view) {
		if (this.state.view === view) { return; }
		this.state.view = view;
		this.render();
	};
	Calendar.prototype.toggleSource = function (src) {
		this.state.hiddenSources[src] = !this.state.hiddenSources[src];
		this.render();
	};

	/* ---- Range label ------------------------------------------------------- */
	Calendar.prototype.rangeLabel = function () {
		var c = this.state.cursor, tz = this.tz;
		if (this.state.view === 'month') {
			return fmt(tz, { month: 'long', year: 'numeric' }).format(c);
		}
		if (this.state.view === 'week') {
			var days = this.weekDays();
			var a = fmt(tz, { month: 'short', day: 'numeric' }).format(days[0]);
			var b = fmt(tz, { month: 'short', day: 'numeric', year: 'numeric' }).format(days[6]);
			return a + ' – ' + b;
		}
		return fmt(tz, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(c);
	};

	/* ---- Cell-date math (anchored to the configured-tz day) ---------------- */

	/** Start-of-month Date for the cursor (browser-local anchor; display in tz). */
	Calendar.prototype.monthCells = function () {
		var c = this.state.cursor;
		var first = new Date(c.getFullYear(), c.getMonth(), 1);
		// Lead days: back up to the preceding Sunday.
		var start = new Date(first.getTime());
		start.setDate(first.getDate() - first.getDay());
		var cells = [];
		for (var i = 0; i < 42; i++) {
			cells.push(new Date(start.getTime() + i * DAY_MS));
		}
		return cells;
	};

	/** The 7 Date objects (Sun..Sat) for the cursor's week. */
	Calendar.prototype.weekDays = function () {
		var c = this.state.cursor;
		var start = new Date(c.getFullYear(), c.getMonth(), c.getDate());
		start.setDate(start.getDate() - start.getDay());
		var days = [];
		for (var i = 0; i < 7; i++) {
			days.push(new Date(start.getTime() + i * DAY_MS));
		}
		return days;
	};

	/** Events whose start falls on the given day (configured tz), sorted by start. */
	Calendar.prototype.eventsForDay = function (date) {
		var key = dayKeyInTz(date, this.tz), tz = this.tz;
		return this.visibleEvents().filter(function (ev) {
			return dayKeyInTz(new Date(ev.start), tz) === key;
		}).sort(function (a, b) {
			if (a.all_day !== b.all_day) { return a.all_day ? -1 : 1; }
			return new Date(a.start) - new Date(b.start);
		});
	};

	/* ---- Chip element ------------------------------------------------------ */
	Calendar.prototype.makeChip = function (ev) {
		var self = this;
		var chip = document.createElement('button');
		chip.type = 'button';
		chip.className = 'gs-cal-chip ' +
			(ev.all_day ? 'gs-cal-chip--allday' : 'gs-cal-chip--timed');
		chip.style.setProperty('--chip-accent', ev.color);
		chip.setAttribute('data-source', ev.source);
		chip.setAttribute('data-event-id', ev.id);
		chip.setAttribute('aria-haspopup', 'dialog');
		if (ev.status === 'cancelled') { chip.classList.add('is-cancelled'); }
		if (ev.status === 'done') { chip.classList.add('is-done'); }

		if (!ev.all_day) {
			var t = document.createElement('span');
			t.className = 'gs-cal-chip__time';
			t.textContent = timeLabel(new Date(ev.start), this.tz);
			chip.appendChild(t);
		}
		var label = document.createElement('span');
		label.className = 'gs-cal-chip__title';
		label.textContent = ev.title;
		chip.appendChild(label);

		chip.addEventListener('click', function (e) {
			e.stopPropagation();
			self.openPopover(ev, chip);
		});
		chip.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				self.openPopover(ev, chip);
			}
		});
		return chip;
	};

	/* ---- Toolbar ----------------------------------------------------------- */
	Calendar.prototype.makeToolbar = function () {
		var self = this;
		var bar = document.createElement('div');
		bar.className = 'gs-cal-toolbar';

		// Nav cluster.
		var nav = document.createElement('div');
		nav.className = 'gs-cal-nav';
		function navBtn(label, aria, fn) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'gs-cal-btn';
			b.textContent = label;
			b.setAttribute('aria-label', aria);
			b.addEventListener('click', fn);
			return b;
		}
		nav.appendChild(navBtn('‹', 'Previous', function () { self.shift(-1); }));
		nav.appendChild(navBtn('Today', 'Today', function () { self.goToday(); }));
		nav.appendChild(navBtn('›', 'Next', function () { self.shift(1); }));
		bar.appendChild(nav);

		// Range label.
		var lbl = document.createElement('div');
		lbl.className = 'gs-cal-range';
		lbl.textContent = this.rangeLabel();
		bar.appendChild(lbl);

		// View switch.
		var seg = document.createElement('div');
		seg.className = 'gs-cal-seg';
		seg.setAttribute('role', 'group');
		seg.setAttribute('aria-label', 'Calendar view');
		[['month', 'Month'], ['week', 'Week'], ['day', 'Day']].forEach(function (pair) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'gs-cal-seg__btn' +
				(self.state.view === pair[0] ? ' is-active' : '');
			b.textContent = pair[1];
			b.setAttribute('aria-pressed', self.state.view === pair[0] ? 'true' : 'false');
			b.addEventListener('click', function () { self.setView(pair[0]); });
			seg.appendChild(b);
		});
		bar.appendChild(seg);

		return bar;
	};

	/* ---- Legend + per-source show/hide toggles (CAL-06) -------------------- */
	Calendar.prototype.makeLegend = function () {
		var self = this;
		var row = document.createElement('div');
		row.className = 'gs-cal-legend';
		row.setAttribute('role', 'group');
		row.setAttribute('aria-label', 'Event source filters');

		Object.keys(GS_CAL_SOURCES).forEach(function (src) {
			var meta = GS_CAL_SOURCES[src];
			var hidden = !!self.state.hiddenSources[src];

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'gs-cal-legend__item' + (hidden ? ' is-hidden' : '');
			btn.setAttribute('data-source', src);
			// aria-pressed = "is this layer shown?" (true when visible).
			btn.setAttribute('aria-pressed', hidden ? 'false' : 'true');
			btn.style.setProperty('--swatch', meta.color);

			var swatch = document.createElement('span');
			swatch.className = 'gs-cal-legend__swatch';
			swatch.setAttribute('aria-hidden', 'true');
			btn.appendChild(swatch);

			var label = document.createElement('span');
			label.className = 'gs-cal-legend__label';
			label.textContent = meta.label;
			btn.appendChild(label);

			btn.addEventListener('click', function () { self.toggleSource(src); });
			row.appendChild(btn);
		});

		return row;
	};

	/* ---- Month view -------------------------------------------------------- */
	Calendar.prototype.renderMonth = function () {
		var self = this, tz = this.tz;
		var grid = document.createElement('div');
		grid.className = 'gs-cal-grid gs-cal-grid--month';

		// Weekday header row.
		var head = document.createElement('div');
		head.className = 'gs-cal-dow-row';
		['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function (d) {
			var h = document.createElement('div');
			h.className = 'gs-cal-dow';
			h.textContent = d;
			head.appendChild(h);
		});
		grid.appendChild(head);

		var body = document.createElement('div');
		body.className = 'gs-cal-cells';

		var cursorMonth = this.state.cursor.getMonth();
		var todayKey = dayKeyInTz(new Date(), tz);
		var cells = this.monthCells();

		cells.forEach(function (date, i) {
			var cell = document.createElement('div');
			cell.className = 'gs-cal-cell';
			cell.style.setProperty('--cell-i', i);
			if (date.getMonth() !== cursorMonth) { cell.classList.add('is-adjacent'); }
			if (dayKeyInTz(date, tz) === todayKey) { cell.classList.add('is-today'); }

			// Phase 31 — make the day cell a meeting picker. data-gs-date holds the
			// tz-correct day-key; clicking the EMPTY part (not an event chip) opens
			// the Schedule Meeting modal pre-filled with this date at 09:00 local.
			var dayKey = dayKeyInTz(date, tz);
			cell.setAttribute('data-gs-date', dayKey);
			cell.classList.add('gs-cal-cell--clickable');
			cell.title = 'Click to schedule a meeting';
			cell.addEventListener('click', function (e) {
				// Event chips open their own popover (they stopPropagation), but guard
				// anyway: ignore clicks that originate on/inside a chip.
				if (e.target && e.target.closest && e.target.closest('.gs-cal-chip')) { return; }
				dispatchScheduleOpen(localDateTimeForDay(date, tz, 9, 0));
			});

			var num = document.createElement('div');
			num.className = 'gs-cal-cell__num';
			num.textContent = fmt(tz, { day: 'numeric' }).format(date);
			cell.appendChild(num);

			var stack = document.createElement('div');
			stack.className = 'gs-cal-cell__events';
			self.eventsForDay(date).forEach(function (ev) {
				stack.appendChild(self.makeChip(ev));
			});
			cell.appendChild(stack);
			body.appendChild(cell);
		});

		grid.appendChild(body);
		return grid;
	};

	/* ---- Week + Day (shared hour-rail renderer) ---------------------------- */
	Calendar.prototype.renderTimeGrid = function (days) {
		var self = this, tz = this.tz;
		var wrap = document.createElement('div');
		wrap.className = 'gs-cal-grid gs-cal-grid--time' +
			(days.length === 1 ? ' gs-cal-grid--day' : ' gs-cal-grid--week');

		// Column header row (blank corner + each day).
		var header = document.createElement('div');
		header.className = 'gs-cal-time-head';
		var corner = document.createElement('div');
		corner.className = 'gs-cal-time-corner';
		header.appendChild(corner);
		var todayKey = dayKeyInTz(new Date(), tz);
		days.forEach(function (d, i) {
			var col = document.createElement('div');
			col.className = 'gs-cal-cell gs-cal-time-colhead';
			col.style.setProperty('--cell-i', i);
			if (dayKeyInTz(d, tz) === todayKey) { col.classList.add('is-today'); }
			col.textContent = dowDayLabel(d, tz);
			header.appendChild(col);
		});
		wrap.appendChild(header);

		// All-day strip.
		var allRow = document.createElement('div');
		allRow.className = 'gs-cal-allday-row';
		var allLabel = document.createElement('div');
		allLabel.className = 'gs-cal-allday-label';
		allLabel.textContent = 'All day';
		allRow.appendChild(allLabel);
		days.forEach(function (d) {
			var slot = document.createElement('div');
			slot.className = 'gs-cal-allday-slot';
			self.eventsForDay(d).forEach(function (ev) {
				if (ev.all_day) { slot.appendChild(self.makeChip(ev)); }
			});
			allRow.appendChild(slot);
		});
		wrap.appendChild(allRow);

		// Hour rail body.
		var scroll = document.createElement('div');
		scroll.className = 'gs-cal-time-scroll';
		var grid = document.createElement('div');
		grid.className = 'gs-cal-time-body';
		grid.style.setProperty('--day-cols', days.length);

		// Hour-label rail.
		var rail = document.createElement('div');
		rail.className = 'gs-cal-hour-rail';
		for (var h = 0; h < 24; h++) {
			var hl = document.createElement('div');
			hl.className = 'gs-cal-hour-mark';
			var probe = new Date(2000, 0, 1, h, 0, 0);
			hl.textContent = fmt(tz, { hour: 'numeric' }).format(probe);
			rail.appendChild(hl);
		}
		grid.appendChild(rail);

		// Day columns with hour grid + absolutely-positioned timed events.
		days.forEach(function (d, ci) {
			var col = document.createElement('div');
			col.className = 'gs-cal-day-col';
			col.style.setProperty('--cell-i', ci);
			(function (dayDate) {
				for (var hh = 0; hh < 24; hh++) {
					var slot = document.createElement('div');
					slot.className = 'gs-cal-hour-slot gs-cal-cell--clickable';
					// Phase 31 — clicking a time slot opens the Schedule Meeting modal
					// pre-filled with this day at this slot's wall-clock hour:00.
					var slotLocal = localDateTimeForDay(dayDate, tz, hh, 0);
					slot.setAttribute('data-gs-datetime', slotLocal);
					slot.title = 'Click to schedule a meeting';
					(function (localStart) {
						slot.addEventListener('click', function (e) {
							if (e.target && e.target.closest && e.target.closest('.gs-cal-chip')) { return; }
							dispatchScheduleOpen(localStart);
						});
					})(slotLocal);
					col.appendChild(slot);
				}
			})(d);
			self.eventsForDay(d).forEach(function (ev) {
				if (ev.all_day) { return; }
				var sd = new Date(ev.start);
				var p = self._minutesInTz(sd);
				var ed = ev.end ? new Date(ev.end) : new Date(sd.getTime() + 30 * 60000);
				var durMin = Math.max(20, (ed - sd) / 60000);
				var chip = self.makeChip(ev);
				chip.classList.add('gs-cal-chip--abs');
				chip.style.top = (p / 1440 * 100) + '%';
				chip.style.height = (durMin / 1440 * 100) + '%';
				col.appendChild(chip);
			});
			grid.appendChild(col);
		});

		scroll.appendChild(grid);
		wrap.appendChild(scroll);
		return wrap;
	};

	/** Minutes-from-midnight for a Date as seen in the configured tz. */
	Calendar.prototype._minutesInTz = function (date) {
		var parts = fmt(this.tz, { hour: '2-digit', minute: '2-digit', hour12: false })
			.formatToParts(date);
		var o = {};
		parts.forEach(function (p) { o[p.type] = p.value; });
		var hr = (+o.hour) % 24;
		return hr * 60 + (+o.minute);
	};

	Calendar.prototype.renderWeek = function () {
		var days = this.weekDays();
		var el = this.renderTimeGrid(days);
		var bounds = this._dayBounds(days);
		this.renderOverlays('week', bounds.fromIso, bounds.toIso, days);
		return el;
	};
	Calendar.prototype.renderDay = function () {
		var c = this.state.cursor;
		var days = [new Date(c.getFullYear(), c.getMonth(), c.getDate())];
		var el = this.renderTimeGrid(days);
		var bounds = this._dayBounds(days);
		this.renderOverlays('day', bounds.fromIso, bounds.toIso, days);
		return el;
	};

	/* ---- Phase 28-03: availability overlays (working hours + blocked time) -- */

	/**
	 * Compute the UTC ISO [from, to) bounds that cover the visible days. We pad to
	 * the start of the first day and the start of the day AFTER the last day in
	 * the configured tz, so the server's half-open expansion covers every column.
	 */
	Calendar.prototype._dayBounds = function (days) {
		// Day-key (YYYY-MM-DD in the configured tz) of first + last visible day.
		var firstKey = dayKeyInTz(days[0], this.tz);
		var lastDate = days[days.length - 1];
		// Move to the next calendar day so the range is half-open and inclusive of the last day.
		var afterLast = new Date(lastDate.getTime() + DAY_MS);
		var afterKey = dayKeyInTz(afterLast, this.tz);
		// Use midnight-UTC of those local day-keys as ISO bounds. A slight tz skew
		// here is harmless — the server expands each local day independently and the
		// renderer matches overlays to columns by local day-key, not by raw bounds.
		return { fromIso: firstKey + 'T00:00:00Z', toIso: afterKey + 'T00:00:00Z' };
	};

	/**
	 * Fetch (or reuse cached) overlays for the range and render them as background
	 * bars on the time grid. Skips Month view (overlays live on the time grid only).
	 */
	Calendar.prototype.renderOverlays = function (viewName, fromIso, toIso, days) {
		if (viewName !== 'week' && viewName !== 'day') { return; }
		var key = viewName + '|' + fromIso + '|' + toIso;
		var self = this;
		var renderIntoGrid = function (data) {
			var grid = self.root.querySelector('.gs-cal-time-body'); // Plan 26-02 day-column container
			if (!grid) { return; }
			// Clear any prior overlays.
			grid.querySelectorAll('.gs-cal-overlay').forEach(function (n) { n.remove(); });
			// Map each visible day-key to its column element (day columns are ordered).
			var cols = grid.querySelectorAll('.gs-cal-day-col');
			var colByKey = {};
			(days || []).forEach(function (d, i) {
				if (cols[i]) { colByKey[dayKeyInTz(d, self.tz)] = cols[i]; }
			});
			// Render working_hours (green) THEN blocked (red) so red stripes sit on top.
			(data.working_hours || []).forEach(function (o) { self._renderOverlayBar(colByKey, o, 'working'); });
			(data.blocked_ranges || []).forEach(function (o) { self._renderOverlayBar(colByKey, o, 'blocked'); });
		};
		if (_overlayCache.key === key && _overlayCache.data) {
			renderIntoGrid(_overlayCache.data);
			return;
		}
		var url = ((window.wpApiSettings && window.wpApiSettings.root) || '/wp-json/').replace(/\/$/, '')
			+ '/gs/v1/calendar/overlays?from=' + encodeURIComponent(fromIso) + '&to=' + encodeURIComponent(toIso);
		fetch(url, {
			credentials: 'same-origin',
			headers: (window.gsAvailNonce || (window.wpApiSettings && window.wpApiSettings.nonce))
				? { 'X-WP-Nonce': (window.gsAvailNonce || window.wpApiSettings.nonce) } : {}
		}).then(function (r) { return r.json(); }).then(function (data) {
			if (!data || data.code) { return; } // WP_Error envelope => skip silently
			_overlayCache.key = key; _overlayCache.data = data;
			renderIntoGrid(data);
		}).catch(function () { /* silent — overlays are decorative */ });
	};

	/**
	 * Render a single overlay bar inside the correct day column. Uses the same
	 * percentage placement as timed chips: top = minutesFromMidnight/1440, height
	 * = duration/1440 (both in the configured tz so DST-correct y-positions hold).
	 */
	Calendar.prototype._renderOverlayBar = function (colByKey, overlay, kind) {
		var start = new Date(overlay.start_utc);
		var end = new Date(overlay.end_utc);
		if (isNaN(start.getTime()) || isNaN(end.getTime())) { return; }
		var dayKey = dayKeyInTz(start, this.tz);
		var col = colByKey[dayKey];
		if (!col) { return; } // overlay falls outside the visible columns
		var startMins = this._minutesInTz(start);
		var endMins = this._minutesInTz(end);
		// Same-day clamp: if the overlay crosses midnight in the configured tz, cap at end-of-day.
		if (endMins <= startMins) { endMins = 1440; }
		var bar = document.createElement('div');
		bar.className = 'gs-cal-overlay gs-cal-overlay-' + kind;
		bar.style.top = (startMins / 1440 * 100) + '%';
		bar.style.height = (Math.max(8, endMins - startMins) / 1440 * 100) + '%';
		col.appendChild(bar);
	};

	/* ---- Master render ----------------------------------------------------- */
	Calendar.prototype.render = function () {
		var root = this.root;
		this.closePopover();
		root.textContent = '';
		root.classList.add('gs-cal-ready');

		root.appendChild(this.makeToolbar());
		root.appendChild(this.makeLegend());

		var viewEl;
		if (this.state.view === 'week') { viewEl = this.renderWeek(); }
		else if (this.state.view === 'day') { viewEl = this.renderDay(); }
		else { viewEl = this.renderMonth(); }
		root.appendChild(viewEl);

		// Trigger the stagger reveal. Reduced-motion => mark already-revealed.
		if (this.reduced) {
			viewEl.classList.add('gs-cal-no-anim');
		} else {
			// Force a reflow then add the trigger so the keyframe replays each render.
			void viewEl.offsetWidth;
			viewEl.classList.add('gs-cal-reveal');
		}
	};

	/* ---- Event detail popover (CAL-04) ------------------------------------ */

	/** Human time range; "All day" + date-only label for all_day (Pitfall 13). */
	Calendar.prototype.timeRangeLabel = function (ev) {
		var tz = this.tz;
		if (ev.all_day) {
			return 'All day · ' +
				fmt(tz, { weekday: 'short', month: 'short', day: 'numeric' })
					.format(new Date(ev.start));
		}
		var sd = new Date(ev.start);
		var dateStr = fmt(tz, { weekday: 'short', month: 'short', day: 'numeric' }).format(sd);
		var startStr = timeLabel(sd, tz);
		if (ev.end) {
			return dateStr + ' · ' + startStr + ' – ' + timeLabel(new Date(ev.end), tz);
		}
		return dateStr + ' · ' + startStr;
	};

	Calendar.prototype.openPopover = function (ev, anchor) {
		var self = this;
		this.closePopover();

		var pop = document.createElement('div');
		pop.className = 'gs-cal-popover';
		pop.setAttribute('role', 'dialog');
		pop.setAttribute('aria-label', 'Event details');
		pop.style.setProperty('--pop-accent', ev.color);

		var meta = GS_CAL_SOURCES[ev.source] || { label: ev.source, color: ev.color };

		// Title.
		var title = document.createElement('div');
		title.className = 'gs-cal-popover__title';
		title.textContent = ev.title;
		pop.appendChild(title);

		// Time range.
		var time = document.createElement('div');
		time.className = 'gs-cal-popover__time';
		time.textContent = this.timeRangeLabel(ev);
		pop.appendChild(time);

		// Source row (swatch + label).
		var srcRow = document.createElement('div');
		srcRow.className = 'gs-cal-popover__source';
		var sw = document.createElement('span');
		sw.className = 'gs-cal-popover__swatch';
		sw.style.background = meta.color;
		sw.setAttribute('aria-hidden', 'true');
		srcRow.appendChild(sw);
		var srcLabel = document.createElement('span');
		srcLabel.textContent = meta.label;
		srcRow.appendChild(srcLabel);
		pop.appendChild(srcRow);

		// Status.
		var status = document.createElement('div');
		status.className = 'gs-cal-popover__status';
		status.textContent = 'Status: ' + ev.status;
		pop.appendChild(status);

		// View-source link (href via property, never innerHTML).
		if (ev.url) {
			var link = document.createElement('a');
			link.className = 'gs-cal-popover__link';
			link.textContent = 'View source';
			link.href = ev.url;
			link.rel = 'noopener';
			pop.appendChild(link);
		}

		// Phase 30: native gend video Join button. Only for meeting events whose
		// meeting_type is 'video' AND meeting_meta.provider === 'gend'. The button
		// carries data-gs-jitsi-join="{meeting_id}" so the jitsi-embed.js delegated
		// click handler opens the glassmorphic embed (which then time-gates + mints
		// the JWT server-side via /jitsi-jwt). The numeric meeting id is read from
		// ev.meeting_id when present, falling back to the trailing digits of ev.id
		// (e.g. 'mt-meet-301' -> 301). The template lives in #gs-jitsi-join-tpl
		// (printed by member-calendar.php only when the embed assets enqueued).
		(function () {
			if (!ev || (ev.source !== 'meetings' && ev.source !== 'mtg')) { return; }
			var mmeta = ev.meeting_meta || {};
			var mtype = ev.meeting_type || mmeta.type || '';
			var provider = mmeta.provider || ev.provider || '';
			if (mtype !== 'video' || provider !== 'gend') { return; }
			var mid = parseInt(ev.meeting_id, 10);
			if (!mid || mid < 1) {
				var m = String(ev.id || '').match(/(\d+)\s*$/);
				mid = m ? parseInt(m[1], 10) : 0;
			}
			if (!mid || mid < 1) { return; }
			var joinBtn;
			var tpl = document.getElementById('gs-jitsi-join-tpl');
			if (tpl && tpl.content && tpl.content.firstElementChild) {
				joinBtn = tpl.content.firstElementChild.cloneNode(true);
				joinBtn.setAttribute('data-gs-jitsi-join', String(mid));
			} else {
				joinBtn = document.createElement('button');
				joinBtn.type = 'button';
				joinBtn.className = 'gs-jitsi-join-btn';
				joinBtn.setAttribute('data-gs-jitsi-join', String(mid));
				joinBtn.textContent = 'Join native video meeting';
			}
			pop.appendChild(joinBtn);
		})();

		// Close button.
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'gs-cal-popover__close';
		close.setAttribute('aria-label', 'Close');
		close.textContent = '×';
		close.addEventListener('click', function () { self.closePopover(); });
		pop.appendChild(close);

		this.root.appendChild(pop);
		this._popover = pop;
		this._popAnchor = anchor;
		this._positionPopover(pop, anchor);

		// Outside-click + Esc closers (bound once, removed on close).
		this._onDocClick = function (e) {
			if (pop.contains(e.target) || (anchor && anchor.contains(e.target))) { return; }
			self.closePopover();
		};
		this._onKey = function (e) {
			if (e.key === 'Escape') { self.closePopover(); }
		};
		// Defer so the originating click doesn't immediately close it.
		setTimeout(function () {
			document.addEventListener('click', self._onDocClick, true);
			document.addEventListener('keydown', self._onKey, true);
		}, 0);

		close.focus();
	};

	/** Anchor the popover near its chip, kept inside the root's bounds. */
	Calendar.prototype._positionPopover = function (pop, anchor) {
		if (!anchor) { return; }
		var rootBox = this.root.getBoundingClientRect();
		var aBox = anchor.getBoundingClientRect();
		var top = aBox.bottom - rootBox.top + 8;
		var left = aBox.left - rootBox.left;
		// Clamp horizontally so it doesn't overflow the root.
		var maxLeft = this.root.clientWidth - pop.offsetWidth - 8;
		if (left > maxLeft) { left = Math.max(8, maxLeft); }
		if (left < 8) { left = 8; }
		pop.style.top = top + 'px';
		pop.style.left = left + 'px';
	};

	Calendar.prototype.closePopover = function () {
		if (this._onDocClick) {
			document.removeEventListener('click', this._onDocClick, true);
			this._onDocClick = null;
		}
		if (this._onKey) {
			document.removeEventListener('keydown', this._onKey, true);
			this._onKey = null;
		}
		if (this._popover && this._popover.parentNode) {
			this._popover.parentNode.removeChild(this._popover);
		}
		if (this._popAnchor && this._popAnchor.focus) {
			// Return focus to the chip for keyboard users.
			try { this._popAnchor.focus(); } catch (e) {}
		}
		this._popover = null;
		this._popAnchor = null;
	};

	/* ---------------------------------------------------------------------------
	 * Mount
	 * ------------------------------------------------------------------------- */
	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('gs-calendar-app');
		if (!root) { return; }
		var cal = new Calendar(root);
		root.__gsCal = cal;     // expose for debugging / Phase 27 hand-off
		window.__gsCalendar = cal; // Phase 28-03 — handle for the availability-changed listener
		cal.render();
		// Phase 27 — populate from the live events endpoint for the visible range.
		// No-op (renders empty) until the fetch resolves; falls back to mock data
		// only when gsCalendarData is absent (handled in the constructor).
		cal.ensureEvents(true);
	});

	/* Phase 28-03 — when the settings panel saves, re-read the configured tz from
	 * #gs-calendar-app[data-tz] (the panel updates it on save), bust the overlay
	 * cache, and re-render the current view so new working-hours/blocked overlays
	 * (and any tz relabel) appear without a page reload. */
	window.addEventListener('gs:availability-changed', function () {
		_overlayCache.key = ''; _overlayCache.data = null; // bust cache
		var cal = window.__gsCalendar;
		if (cal && typeof cal.render === 'function') {
			var root = cal.root || document.getElementById('gs-calendar-app');
			if (root && root.dataset && root.dataset.tz) { cal.tz = root.dataset.tz; }
			cal.render();
			cal.ensureEvents(true); // re-pull live events too (availability edits can shift meetings)
		} else {
			location.reload(); // graceful fallback — should not trigger when controller is mounted
		}
	});

})();
