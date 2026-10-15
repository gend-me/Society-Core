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
		openPopup(a.getAttribute('href'));
	});

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
	} else {
		qr();
	}
})();
