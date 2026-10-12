/**
 * gend-society connection health (Phase 108, CON-06).
 *
 * After the page has fully loaded, and only when the server says a check is
 * due (localized `due`), ask admin-ajax `gend_society_connection_health`.
 * On "unauthorized" the returned Reconnect notice is inserted after the first
 * `.wrap h1` (or at the top of #wpbody-content) unless one is already shown.
 * Dismissing the notice tells the server which check was dismissed.
 * No timers, no polling: one request at most per admin page load.
 */
(function () {
	'use strict';

	var cfg = window.gendSocietyConnectionHealth;
	if (!cfg || !cfg.ajaxUrl || !window.fetch) {
		return;
	}

	function post(action, extra) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(extra || {}).forEach(function (k) {
			body.append(k, extra[k]);
		});
		return window.fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) {
			return r.json();
		});
	}

	function insertNotice(html) {
		if (document.querySelector('.gs-connection-health-notice')) {
			return;
		}
		var holder = document.createElement('div');
		holder.innerHTML = html;
		var notice = holder.firstElementChild;
		if (!notice) {
			return;
		}
		var h1 = document.querySelector('.wrap h1');
		if (h1 && h1.parentNode) {
			h1.parentNode.insertBefore(notice, h1.nextSibling);
		} else {
			var content = document.getElementById('wpbody-content');
			if (!content) {
				return;
			}
			content.insertBefore(notice, content.firstChild);
		}
		// Let WordPress add its dismiss button to the late notice.
		if (window.jQuery) {
			window.jQuery(document).trigger('wp-updates-notice-added');
		}
	}

	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest ? e.target.closest('.gs-connection-health-notice .notice-dismiss') : null;
		if (!btn) {
			return;
		}
		var notice = btn.closest('.gs-connection-health-notice');
		post('gend_society_connection_health_dismiss', {
			checked_at: (notice && notice.getAttribute('data-gs-health-checked')) || ''
		}).catch(function () {});
	});

	window.addEventListener('load', function () {
		if (!cfg.due) {
			return;
		}
		post('gend_society_connection_health', {}).then(function (r) {
			if (r && r.status === 'unauthorized' && r.notice_html && cfg.inject) {
				insertNotice(r.notice_html);
			}
		}).catch(function () {});
	});
})();
