/*
 * gend-society: GenD > Plan & features (inc/pages/plan.php), standalone installs.
 *
 * - "Choose <plan>" opens the same-origin checkout launcher in a popup synchronously from the click, so popup
 *   blockers allow it. When the browser blocks it anyway, a link opens the same launcher in a new tab.
 * - Completion: a gend_society_plan_changed message is accepted only from the exact hub origin and only for
 *   this install id; otherwise the popup closing (checked every 600 ms) is the signal.
 * - Either way the Refresh AJAX drops the plan caches on the server and returns the cards, which replace the
 *   ones on the page by element id (no reload).
 * Config comes from wp_localize_script (gendSocietyPlan). No jQuery, ES5.
 */
(function () {
	'use strict';

	var cfg = window.gendSocietyPlan || {};
	var i18n = cfg.i18n || {};
	var popup = null;
	var poll = null;
	var busy = false;
	var again = false;
	var refreshes = 0;

	function closest(el, selector) {
		while (el && el.nodeType === 1) {
			if (el.matches ? el.matches(selector) : (el.msMatchesSelector && el.msMatchesSelector(selector))) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function live(text) {
		var el = document.querySelector('[data-gs-plan-live]');
		if (el) {
			el.textContent = text || '';
		}
	}

	function stopPoll() {
		if (poll) {
			window.clearInterval(poll);
			poll = null;
		}
	}

	function hideBlocked() {
		var box = document.getElementById('gs-plan-blocked');
		if (box) {
			box.hidden = true;
		}
	}

	function showBlocked(href) {
		var box = document.getElementById('gs-plan-blocked');
		if (!box) {
			return;
		}
		var link = box.querySelector('[data-gs-plan-blocked-link]');
		if (link) {
			link.setAttribute('href', href);
		}
		box.hidden = false;
		if (link && link.focus) {
			link.focus();
		}
	}

	function replaceFragments(html) {
		var id;
		for (id in html) {
			if (!Object.prototype.hasOwnProperty.call(html, id)) {
				continue;
			}
			var current = document.getElementById(id);
			if (!current || typeof html[id] !== 'string') {
				continue;
			}
			var holder = document.createElement('div');
			holder.innerHTML = html[id];
			var next = holder.firstElementChild;
			if (next && next.id === id) {
				current.parentNode.replaceChild(next, current);
			}
		}
	}

	function done(ok) {
		busy = false;
		refreshes++;
		document.documentElement.setAttribute('data-gs-plan-refreshes', String(refreshes));
		live(ok ? i18n.refreshed : i18n.failed);
		if (again) {
			again = false;
			refresh();
		}
	}

	function refresh() {
		if (!cfg.ajaxUrl) {
			return;
		}
		if (busy) {
			again = true;
			return;
		}
		busy = true;
		live(i18n.refreshing);
		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.ajaxUrl, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		xhr.onload = function () {
			var res = null;
			try {
				res = JSON.parse(xhr.responseText);
			} catch (e) {
				res = null;
			}
			if (xhr.status === 200 && res && res.success && res.data) {
				replaceFragments(res.data.html || {});
				done(true);
				return;
			}
			done(false);
		};
		xhr.onerror = function () {
			done(false);
		};
		xhr.send('action=gend_society_plan_refresh&nonce=' + encodeURIComponent(cfg.nonce || ''));
	}

	function finished() {
		stopPoll();
		popup = null;
		refresh();
	}

	function openCheckout(href) {
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
		live(i18n.waiting);
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
		var checkout = closest(ev.target, '[data-gs-plan-checkout]');
		if (checkout) {
			ev.preventDefault();
			openCheckout(checkout.getAttribute('href'));
			return;
		}
		if (closest(ev.target, '[data-gs-plan-refresh]')) {
			ev.preventDefault();
			refresh();
		}
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
	window.addEventListener('beforeunload', stopPoll);
})();
