/*
 * gend-society: GenD > Groups / Feature Suite / Projects (inc/hub-embed.php), standalone installs.
 *
 * The Refresh button posts the plan Refresh AJAX (gend_society_plan_refresh: the server drops every plan and
 * hub-area cache) with this page's area, then swaps the returned #gs-hub-<area> fragment in place (no reload).
 * The Choose button of an upgrade state is handled by gs-plan.js (same page). Config comes from
 * wp_localize_script (gendSocietyHubEmbed). No jQuery, ES5, no remote requests.
 */
(function () {
	'use strict';

	var cfg = window.gendSocietyHubEmbed || {};
	var i18n = cfg.i18n || {};
	var busy = false;
	var refreshes = 0;

	function live(text) {
		var el = document.querySelector('[data-gs-hub-live]');
		if (el) {
			el.textContent = text || '';
		}
	}

	function swap(html) {
		var id;
		for (id in html) {
			if (!Object.prototype.hasOwnProperty.call(html, id) || typeof html[id] !== 'string') {
				continue;
			}
			var current = document.getElementById(id);
			if (!current) {
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

	function done(ok, button) {
		busy = false;
		refreshes++;
		document.documentElement.setAttribute('data-gs-hub-refreshes', String(refreshes));
		if (button) {
			button.disabled = false;
		}
		live(ok ? i18n.refreshed : i18n.failed);
	}

	function refresh(button) {
		if (busy || !cfg.ajaxUrl || !cfg.area) {
			return;
		}
		busy = true;
		if (button) {
			button.disabled = true;
		}
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
				swap(res.data.html || {});
				done(true, button);
				return;
			}
			done(false, button);
		};
		xhr.onerror = function () {
			done(false, button);
		};
		xhr.send('action=gend_society_plan_refresh&nonce=' + encodeURIComponent(cfg.nonce || '') + '&gs_view=hub&gs_area=' + encodeURIComponent(cfg.area));
	}

	document.addEventListener('click', function (ev) {
		var el = ev.target;
		while (el && el.nodeType === 1) {
			if (el.hasAttribute('data-gs-hub-refresh')) {
				ev.preventDefault();
				refresh(el);
				return;
			}
			el = el.parentNode;
		}
	});
})();
