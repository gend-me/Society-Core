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
		this.state = {
			view: 'month',
			cursor: new Date(),
			hiddenSources: {},          // { projects:true } => hidden
			events: buildMockEvents()
		};
	}

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
	};
	Calendar.prototype.goToday = function () {
		this.state.cursor = new Date();
		this.render();
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

	/* ---- Legend (Task 2 wires toggle behaviour; placeholder host here) ----- */
	Calendar.prototype.makeLegend = function () {
		// Replaced by the interactive legend in Task 2; returns an empty host so
		// Task 1 renders standalone.
		var row = document.createElement('div');
		row.className = 'gs-cal-legend';
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
			for (var hh = 0; hh < 24; hh++) {
				var slot = document.createElement('div');
				slot.className = 'gs-cal-hour-slot';
				col.appendChild(slot);
			}
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
		return this.renderTimeGrid(this.weekDays());
	};
	Calendar.prototype.renderDay = function () {
		var c = this.state.cursor;
		return this.renderTimeGrid([new Date(c.getFullYear(), c.getMonth(), c.getDate())]);
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

	/* ---- Popover (filled in Task 2) --------------------------------------- */
	Calendar.prototype.openPopover = function () { /* Task 2 */ };
	Calendar.prototype.closePopover = function () { /* Task 2 */ };

	/* ---------------------------------------------------------------------------
	 * Mount
	 * ------------------------------------------------------------------------- */
	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('gs-calendar-app');
		if (!root) { return; }
		var cal = new Calendar(root);
		root.__gsCal = cal;     // expose for debugging / Phase 27 hand-off
		cal.render();
	});

})();
