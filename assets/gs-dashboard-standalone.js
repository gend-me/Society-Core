/*
 * gend-society: standalone GenD dashboard (wp-admin/index.php, standalone installs only; inc/pages/dashboard.php
 * enqueues it). Phase 109-29 (D14/D28).
 *
 * - [data-gs-popup] links (membership card UPGRADE = gend.me's own Change Plan page, Add Server, Pay, Choose a
 *   plan) open their href in one gend.me popup window, synchronously from the click so popup blockers allow it.
 *   When the browser blocks it anyway, a notice offers the same page in a new tab.
 * - Completion: a gend_society_plan_changed message is accepted only from the paired hub's exact origin and only
 *   for this install id; otherwise the popup closing (checked every 600 ms) is the signal. Either way the
 *   existing Refresh AJAX (gend_society_plan_refresh) drops the plan, gas and backups caches, then the dashboard
 *   reloads once so every panel shows the new data.
 * - [data-gs-qr] boxes get a QR code from WooCommerce's bundled jquery-qrcode when this site has it (no QR
 *   service); without it they stay hidden and the link next to them is the way in.
 * - button[data-gs-connect-open] belongs to the connect popup (plan 109-30); nothing here handles it.
 * - 110-12 (M2/M7): the Storage card Start Migration CTA opens the popup on the local wait page first
 *   (cfg.migrationWaitUrl); the membership header progress ([data-gs-mig-root]) gets Start / Confirm / Cancel /
 *   Retry (this site's own admin-ajax, cfg.migNonce) and repaints every 15 s while armed, scheduled or running;
 *   a[data-gs-start-inline] (the CTA once armed) opens the inline Start step.
 * Config: wp_localize_script (gendSocietyDashboard). ES5, no jQuery needed.
 */
(function () {
	'use strict';

	var cfg = window.gendSocietyDashboard || {};
	var i18n = cfg.i18n || {};
	var popup = null;
	var poll = null;
	var refreshing = false;

	function closest(el, selector) {
		while (el && el.nodeType === 1) {
			if (el.matches ? el.matches(selector) : (el.msMatchesSelector && el.msMatchesSelector(selector))) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function stopPoll() {
		if (poll) {
			window.clearInterval(poll);
			poll = null;
		}
	}

	function blockedBox() {
		var box = document.getElementById('gs-dash-sa-blocked');
		if (box) {
			return box;
		}
		box = document.createElement('div');
		box.id = 'gs-dash-sa-blocked';
		box.className = 'gs-dash-sa-blocked';
		box.setAttribute('role', 'alert');
		box.hidden = true;
		var text = document.createElement('span');
		text.textContent = (i18n.blocked || 'Your browser blocked the gend.me window.') + ' ';
		var link = document.createElement('a');
		link.setAttribute('data-gs-dash-blocked-link', '');
		link.setAttribute('target', '_blank');
		link.setAttribute('rel', 'opener');
		link.textContent = i18n.openTab || 'Open it in a new tab';
		box.appendChild(text);
		box.appendChild(link);
		document.body.appendChild(box);
		return box;
	}

	function showBlocked(href) {
		var box = blockedBox();
		var link = box.querySelector('[data-gs-dash-blocked-link]');
		link.setAttribute('href', href);
		box.hidden = false;
		if (link.focus) {
			link.focus();
		}
	}

	function hideBlocked() {
		var box = document.getElementById('gs-dash-sa-blocked');
		if (box) {
			box.hidden = true;
		}
	}

	function reload() {
		window.location.reload();
	}

	// Drop the caches on the server (existing gend_society_plan_refresh AJAX), then show the fresh panels.
	function refresh() {
		if (refreshing) {
			return;
		}
		refreshing = true;
		document.documentElement.setAttribute('data-gs-dash-refreshing', '1');
		if (!cfg.ajaxUrl || !cfg.nonce) {
			reload();
			return;
		}
		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		xhr.onload = reload;
		xhr.onerror = reload;
		xhr.send('action=gend_society_plan_refresh&nonce=' + encodeURIComponent(cfg.nonce));
	}

	function finished() {
		stopPoll();
		popup = null;
		refresh();
	}

	function openPopup(href) {
		hideBlocked();
		var w = null;
		try {
			w = window.open(href, 'gend_society_checkout', 'width=980,height=820');
		} catch (e) {
			w = null;
		}
		if (!w) {
			showBlocked(href);
			return;
		}
		popup = w;
		try {
			w.focus();
		} catch (e) {}
		stopPoll();
		poll = window.setInterval(function () {
			var closed = true;
			try {
				closed = !popup || popup.closed;
			} catch (e) {
				closed = true;
			}
			if (closed) {
				finished();
			}
		}, 600);
	}

	document.addEventListener('click', function (ev) {
		var a = closest(ev.target, 'a[data-gs-popup]');
		if (!a || !a.getAttribute('href')) {
			return;
		}
		ev.preventDefault();
		// 110-12 (M7): the Storage card Start Migration CTA opens the popup on the local wait page first; it keeps
		// gend.me's pre-flight current, then goes on to the container wizard (still one window, opened by the click).
		openPopup(a.getAttribute('data-gs-migrate-cta') === '1' && cfg.migrationWaitUrl ? cfg.migrationWaitUrl : a.getAttribute('href'));
	});

	/*
	 * 110-12 (M2): the membership header progress on a connected install. Start (opens the inline Start step),
	 * Confirm (start now / schedule), Cancel, Retry go to this site's own admin-ajax (it talks to gend.me); the
	 * header repaints every 15 s while armed, scheduled or running and stops when Live or failed.
	 */
	var migPoll = null;

	function migRoot() {
		return document.querySelector('[data-gs-mig-root]');
	}

	function migSay(msg) {
		var r = migRoot();
		var m = r ? r.querySelector('[data-gs-mig-msg]') : null;
		if (m) {
			m.textContent = msg || '';
		}
	}

	function migPost(action, fields, done) {
		if (!cfg.ajaxUrl || !cfg.migNonce) {
			return;
		}
		var body = 'action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(cfg.migNonce);
		Object.keys(fields || {}).forEach(function (k) {
			body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(fields[k]);
		});
		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		xhr.onload = function () {
			var j = null;
			try {
				j = JSON.parse(xhr.responseText);
			} catch (e) {
				j = null;
			}
			done(j);
		};
		xhr.onerror = function () {
			done(null);
		};
		xhr.send(body);
	}

	function migOpenStart() {
		var r = migRoot();
		var step = r ? r.querySelector('[data-gs-mig-start-step]') : null;
		if (!step) {
			return;
		}
		step.hidden = false;
		var b = r.querySelector('[data-gs-mig-action="start-open"]');
		if (b) {
			b.setAttribute('aria-expanded', 'true');
		}
		if (step.scrollIntoView) {
			step.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
	}

	function migAction(btn) {
		var a = btn.getAttribute('data-gs-mig-action');
		var r = migRoot();
		if (!r) {
			return;
		}
		if (a === 'start-open') {
			var step = r.querySelector('[data-gs-mig-start-step]');
			if (step && !step.hidden) {
				step.hidden = true;
				btn.setAttribute('aria-expanded', 'false');
				return;
			}
			migOpenStart();
			return;
		}
		if (a === 'cancel' && btn.getAttribute('data-confirm') && !window.confirm(btn.getAttribute('data-confirm'))) {
			return;
		}
		var fields = {};
		if (a === 'start') {
			var ack = r.querySelector('input[name="gs_mig_freeze_ack"]');
			if (!ack || !ack.checked) {
				migSay(i18n.migAck || 'Tick "I understand" first.');
				return;
			}
			fields.freeze_ack = '1';
			var when = r.querySelector('input[name="gs_mig_when"]:checked');
			if (when && when.value === 'schedule') {
				var v = (r.querySelector('input[name="gs_mig_start_at"]') || {}).value || '';
				var ts = v ? Math.floor(new Date(v).getTime() / 1000) : 0; // the browser's local time -> UTC seconds
				if (!ts) {
					migSay(i18n.migPickTime || 'Pick a date and time.');
					return;
				}
				fields.start_at = String(ts);
			}
		}
		btn.disabled = true;
		migSay(i18n.migWorking || 'Working...');
		migPost('gend_society_migration_' + a, fields, function (j) {
			if (j && j.success) {
				reload();
				return;
			}
			btn.disabled = false;
			migSay((j && j.data && j.data.message) || i18n.migFailed || 'Something went wrong. Please try again.');
		});
	}

	function migRepaint() {
		migPost('gend_society_migration_state', {}, function (j) {
			if (!j || !j.success || !j.data) {
				return;
			}
			var r = migRoot();
			if (!r) {
				return;
			}
			var open = r.querySelector('[data-gs-mig-start-step]');
			var keep = open && !open.hidden;
			var was = r.getAttribute('data-state');
			var now = j.data.migration && j.data.migration.state ? j.data.migration.state : '';
			if (!keep && now && was && now !== was && (now === 'failed' || now === 'complete')) {
				reload(); // the status badge (friendly error) and the panels change with it
				return;
			}
			if (typeof j.data.html === 'string' && j.data.html !== '') {
				var tmp = document.createElement('div');
				tmp.innerHTML = j.data.html;
				var fresh = tmp.firstElementChild;
				if (fresh && !keep) {
					r.parentNode.replaceChild(fresh, r);
				}
			} else if (!keep) {
				reload();
				return;
			}
			if (!j.data.poll && migPoll) {
				window.clearInterval(migPoll);
				migPoll = null;
			}
		});
	}

	document.addEventListener('click', function (ev) {
		var btn = closest(ev.target, '[data-gs-mig-action]');
		if (btn) {
			ev.preventDefault();
			migAction(btn);
			return;
		}
		var inline = closest(ev.target, 'a[data-gs-start-inline]');
		if (inline) {
			ev.preventDefault();
			migOpenStart();
		}
	});

	function migStart() {
		var r = migRoot();
		if (r && r.getAttribute('data-poll') === '1' && !migPoll) {
			migPoll = window.setInterval(migRepaint, 15000);
		}
	}

	// Only the paired hub, only this install, only this message type.
	window.addEventListener('message', function (ev) {
		if (!cfg.hubOrigin || ev.origin !== cfg.hubOrigin) {
			return;
		}
		var d = ev.data;
		if (!d || typeof d !== 'object' || d.type !== 'gend_society_plan_changed' || !cfg.installId || d.install_id !== cfg.installId) {
			return;
		}
		if (popup) {
			try {
				popup.close();
			} catch (e) {}
		}
		finished();
	});

	window.addEventListener('pagehide', stopPoll);

	function qr() {
		var $ = window.jQuery;
		var boxes = document.querySelectorAll('[data-gs-qr]');
		for (var i = 0; i < boxes.length; i++) {
			var box = boxes[i];
			if (!$ || !$.fn || typeof $.fn.qrcode !== 'function') {
				box.hidden = true;
				continue;
			}
			try {
				box.innerHTML = '';
				$(box).qrcode({ render: 'canvas', width: 200, height: 200, text: box.getAttribute('data-gs-qr') });
				box.hidden = false;
			} catch (e) {
				box.innerHTML = '';
				box.hidden = true;
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', qr);
		document.addEventListener('DOMContentLoaded', migStart);
	} else {
		qr();
		migStart();
	}
})();
