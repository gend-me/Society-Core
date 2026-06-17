/* =============================================================================
 * GenD Society — Public Shared Calendar View (Phase 31-frontend)
 *
 * Read-only month calendar served at /calendar-view/{share_token}/ for
 * logged-OUT viewers. Adapts the member-calendar.js month renderer (tz-aware
 * Intl formatting, --gph-* color resolution, event chips, glass styling) — but
 * with NO booking controls, NO Schedule button, NO event editing.
 *
 * Data is fetched from the token-gated, auth-gate-bypassing REST endpoints:
 *   GET {restBase}calendar/public/{token}/info
 *       → 403 gs_cal_private → "This calendar is private." card
 *       → 404               → "Calendar not found." card
 *       → 200               → identity header (only fields present) + month grid
 *   GET {restBase}calendar/public/{token}/events?from=&to=
 *       → { events:[...], member_tz, detail }  (busy mode events arrive collapsed)
 *
 * Privacy is enforced SERVER-SIDE; this script just renders whatever the API
 * returns (and the appropriate error card off the status code).
 * ========================================================================== */
(function () {
	'use strict';

	var DATA = window.gsCalViewData || {};
	var REST = String(DATA.restBase || '/wp-json/gs/v1/').replace(/\/$/, '');
	var TOKEN = String(DATA.token || '');
	var DAY_MS = 86400000;

	/* --gph-* design tokens → concrete colors (mirrors member-calendar.css :root).
	 * The events API returns colors as '--gph-*' token NAMES; resolve to hex. */
	var GPH = {
		'--gph-blue': '#89C2E0',
		'--gph-magenta': '#b608c9',
		'--gph-green': '#00ff88',
		'--gph-grey': '#8b8fa3'
	};
	function resolveColor(c) {
		if (!c) { return GPH['--gph-blue']; }
		if (c.charAt(0) === '#') { return c; }
		if (GPH[c]) { return GPH[c]; }
		// Try live CSS custom property, else fall back to blue.
		try {
			var v = getComputedStyle(document.documentElement).getPropertyValue(c).trim();
			if (v) { return v; }
		} catch (e) {}
		return GPH['--gph-blue'];
	}

	/* Source label map for full-detail chips/legend. */
	var SOURCE_LABELS = {
		projects: 'Projects', pm: 'Projects',
		campaigns: 'Campaigns', bm: 'Campaigns',
		meetings: 'Meetings', mtg: 'Meetings',
		busy: 'Busy'
	};

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

	/* ---- tz-aware date helpers (from member-calendar.js) ------------------- */
	var _fmtCache = {};
	function fmt(tz, opts) {
		var key = tz + '|' + JSON.stringify(opts);
		if (!_fmtCache[key]) {
			_fmtCache[key] = new Intl.DateTimeFormat('en-US', Object.assign({ timeZone: tz }, opts));
		}
		return _fmtCache[key];
	}
	function ymdInTz(date, tz) {
		var parts = fmt(tz, { year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
		var o = {};
		parts.forEach(function (p) { o[p.type] = p.value; });
		return { y: +o.year, m: +o.month, d: +o.day };
	}
	function dayKeyInTz(date, tz) {
		var p = ymdInTz(date, tz);
		return p.y + '-' + String(p.m).padStart(2, '0') + '-' + String(p.d).padStart(2, '0');
	}
	function timeLabel(date, tz) {
		return fmt(tz, { hour: 'numeric', minute: '2-digit' }).format(date);
	}

	/* ---- REST ------------------------------------------------------------- */
	function getJSON(url) {
		return fetch(url, { credentials: 'omit' }).then(function (r) {
			return r.json().then(
				function (j) { return { ok: r.ok, status: r.status, body: j }; },
				function () { return { ok: r.ok, status: r.status, body: null }; }
			);
		});
	}

	/* ---- Viewer controller ------------------------------------------------ */
	function Viewer(root) {
		this.root = root;
		this.tz = 'UTC';
		this.detail = 'busy';
		this.info = null;
		this.events = [];
		this.cursor = new Date();
		this._fetchedKey = '';
	}

	Viewer.prototype.start = function () {
		var self = this;
		getJSON(REST + '/calendar/public/' + encodeURIComponent(TOKEN) + '/info').then(function (res) {
			if (res.status === 403) { return self.renderCard('This calendar is private.', 'Its owner has not shared it publicly.'); }
			if (res.status === 404) { return self.renderCard('Calendar not found.', 'This share link is invalid or has been revoked.'); }
			if (!res.ok || !res.body || typeof res.body !== 'object') {
				return self.renderCard('Calendar unavailable.', 'Please try again later.');
			}
			self.info = res.body;
			if (res.body.detail === 'full' || res.body.detail === 'busy') { self.detail = res.body.detail; }
			self.render();
			self.fetchMonth(true);
		}).catch(function () {
			self.renderCard('Calendar unavailable.', 'A network error occurred.');
		});
	};

	/* Visible month [from, to) in UTC ISO — first..last day of cursor's month
	 * padded a day each side so tz-edge events land. */
	Viewer.prototype.monthRange = function () {
		var c = this.cursor;
		var from = new Date(c.getFullYear(), c.getMonth() - 0, 1, 0, 0, 0);
		from = new Date(from.getTime() - DAY_MS);
		var to = new Date(c.getFullYear(), c.getMonth() + 1, 1, 0, 0, 0);
		to = new Date(to.getTime() + DAY_MS);
		return { fromISO: from.toISOString(), toISO: to.toISOString() };
	};

	Viewer.prototype.fetchMonth = function (force) {
		var self = this;
		var r = this.monthRange();
		var key = r.fromISO + '|' + r.toISO;
		if (!force && key === this._fetchedKey) { return; }
		var url = REST + '/calendar/public/' + encodeURIComponent(TOKEN) + '/events'
			+ '?from=' + encodeURIComponent(r.fromISO) + '&to=' + encodeURIComponent(r.toISO);
		getJSON(url).then(function (res) {
			if (!res.ok || !res.body) { return; } // keep current events; privacy already handled by info
			var body = res.body;
			if (body.member_tz) { self.tz = body.member_tz; }
			if (body.detail === 'full' || body.detail === 'busy') { self.detail = body.detail; }
			self.events = Array.isArray(body.events) ? body.events : [];
			self._fetchedKey = key;
			self.render();
		}).catch(function () { /* keep current */ });
	};

	/* ---- Render: identity header + month grid ----------------------------- */
	Viewer.prototype.render = function () {
		var root = this.root;
		root.textContent = '';
		root.classList.add('gs-cal-ready');

		var shell = el('div', { class: 'gs-calview-shell' });
		shell.appendChild(this.renderHeader());
		shell.appendChild(this.renderToolbar());
		shell.appendChild(this.renderMonth());
		shell.appendChild(this.renderFooter());
		root.appendChild(shell);
	};

	Viewer.prototype.renderHeader = function () {
		var info = this.info || {};
		var head = el('div', { class: 'gs-calview-header' });

		if (info.avatar_url) {
			head.appendChild(el('img', { class: 'gs-calview-avatar', src: info.avatar_url, alt: '' }));
		}
		var meta = el('div', { class: 'gs-calview-headmeta' });
		if (info.display_name) {
			meta.appendChild(el('div', { class: 'gs-calview-name', text: info.display_name }));
		} else {
			meta.appendChild(el('div', { class: 'gs-calview-name', text: 'Shared calendar' }));
		}
		var sub = [];
		if (info.timezone) { sub.push(info.timezone); }
		if (sub.length) { meta.appendChild(el('div', { class: 'gs-calview-sub', text: sub.join(' · ') })); }
		if (info.bio) { meta.appendChild(el('div', { class: 'gs-calview-bio', text: info.bio })); }
		head.appendChild(meta);
		return head;
	};

	Viewer.prototype.renderToolbar = function () {
		var self = this, tz = this.tz;
		var bar = el('div', { class: 'gs-cal-toolbar gs-calview-toolbar' });

		var nav = el('div', { class: 'gs-cal-nav' });
		function navBtn(label, aria, fn) {
			var b = el('button', { type: 'button', class: 'gs-cal-btn', 'aria-label': aria, text: label });
			b.addEventListener('click', fn);
			return b;
		}
		nav.appendChild(navBtn('‹', 'Previous month', function () { self.shift(-1); }));
		nav.appendChild(navBtn('Today', 'Today', function () { self.goToday(); }));
		nav.appendChild(navBtn('›', 'Next month', function () { self.shift(1); }));
		bar.appendChild(nav);

		bar.appendChild(el('div', { class: 'gs-cal-range',
			text: fmt(tz, { month: 'long', year: 'numeric' }).format(this.cursor) }));

		// Read-only mode badge (full vs busy).
		bar.appendChild(el('div', { class: 'gs-calview-mode',
			text: this.detail === 'full' ? 'Full details' : 'Busy / free' }));
		return bar;
	};

	Viewer.prototype.shift = function (dir) {
		var c = new Date(this.cursor.getTime());
		c.setMonth(c.getMonth() + dir);
		this.cursor = c;
		this.render();
		this.fetchMonth(false);
	};
	Viewer.prototype.goToday = function () {
		this.cursor = new Date();
		this.render();
		this.fetchMonth(false);
	};

	Viewer.prototype.monthCells = function () {
		var c = this.cursor;
		var first = new Date(c.getFullYear(), c.getMonth(), 1);
		var start = new Date(first.getTime());
		start.setDate(first.getDate() - first.getDay());
		var cells = [];
		for (var i = 0; i < 42; i++) { cells.push(new Date(start.getTime() + i * DAY_MS)); }
		return cells;
	};

	Viewer.prototype.eventsForDay = function (date) {
		var key = dayKeyInTz(date, this.tz), tz = this.tz;
		return this.events.filter(function (ev) {
			return ev && ev.start && dayKeyInTz(new Date(ev.start), tz) === key;
		}).sort(function (a, b) {
			if (!!a.all_day !== !!b.all_day) { return a.all_day ? -1 : 1; }
			return new Date(a.start) - new Date(b.start);
		});
	};

	Viewer.prototype.makeChip = function (ev) {
		var chip = el('div', { class: 'gs-cal-chip ' + (ev.all_day ? 'gs-cal-chip--allday' : 'gs-cal-chip--timed') });
		var color = resolveColor(ev.color);
		chip.style.setProperty('--chip-accent', color);
		chip.setAttribute('data-source', ev.source || 'busy');
		if (ev.status === 'cancelled') { chip.classList.add('is-cancelled'); }
		if (!ev.all_day && ev.start) {
			chip.appendChild(el('span', { class: 'gs-cal-chip__time', text: timeLabel(new Date(ev.start), this.tz) }));
		}
		chip.appendChild(el('span', { class: 'gs-cal-chip__title', text: ev.title || 'Busy' }));
		return chip;
	};

	Viewer.prototype.renderMonth = function () {
		var self = this, tz = this.tz;
		var grid = el('div', { class: 'gs-cal-grid gs-cal-grid--month' });

		var head = el('div', { class: 'gs-cal-dow-row' });
		['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function (d) {
			head.appendChild(el('div', { class: 'gs-cal-dow', text: d }));
		});
		grid.appendChild(head);

		var body = el('div', { class: 'gs-cal-cells' });
		var cursorMonth = this.cursor.getMonth();
		var todayKey = dayKeyInTz(new Date(), tz);

		this.monthCells().forEach(function (date, i) {
			var cell = el('div', { class: 'gs-cal-cell' });
			cell.style.setProperty('--cell-i', i);
			if (date.getMonth() !== cursorMonth) { cell.classList.add('is-adjacent'); }
			if (dayKeyInTz(date, tz) === todayKey) { cell.classList.add('is-today'); }
			cell.appendChild(el('div', { class: 'gs-cal-cell__num', text: fmt(tz, { day: 'numeric' }).format(date) }));
			var stack = el('div', { class: 'gs-cal-cell__events' });
			self.eventsForDay(date).forEach(function (ev) { stack.appendChild(self.makeChip(ev)); });
			cell.appendChild(stack);
			body.appendChild(cell);
		});
		grid.appendChild(body);
		return grid;
	};

	Viewer.prototype.renderFooter = function () {
		var foot = el('div', { class: 'gs-calview-footer' });
		var msg = this.detail === 'busy'
			? 'Showing busy / free only. Times shown in ' + this.tz + '.'
			: 'Times shown in ' + this.tz + '.';
		foot.appendChild(el('span', { text: msg }));
		return foot;
	};

	/* ---- Error / private card --------------------------------------------- */
	Viewer.prototype.renderCard = function (title, sub) {
		var root = this.root;
		root.textContent = '';
		var card = el('div', { class: 'gs-calview-card' });
		card.appendChild(el('div', { class: 'gs-calview-card__icon', text: '🔒', 'aria-hidden': 'true' }));
		card.appendChild(el('h1', { class: 'gs-calview-card__title', text: title }));
		if (sub) { card.appendChild(el('p', { class: 'gs-calview-card__sub', text: sub })); }
		root.appendChild(card);
	};

	/* ---- Mount ------------------------------------------------------------ */
	function init() {
		var root = document.getElementById('gs-calview-root');
		if (!root) { return; }
		if (!TOKEN) { return; }
		new Viewer(root).start();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
