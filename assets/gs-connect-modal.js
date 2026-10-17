/*
 * gend-society: Connect popup on the dashboard's Integration Hub card (standalone installs; Phase 109-30, D29).
 * inc/portal-connect.php prints the popup hidden (#gs-connect-modal) and enqueues this file on the dashboard.
 *
 * - button[data-gs-connect-open] (Integration Hub card: Connect to gend.me / Manage connection / Reconnect, and the
 *   plan column's Reconnect) opens it; [data-gs-connect-close] (close button, overlay) and Esc close it.
 * - Focus moves into the dialog, Tab / Shift+Tab stay inside it, and focus returns to the opener on close.
 * - It opens by itself on #gs-connect or when the page was asked for it (data-gs-connect-autoopen="1": the connect
 *   URL ?gs_connect=1, a handler's status notice, or a gend.me /pair code). A pairing code in the URL fills the
 *   field when it has the code pattern (the server already does this; kept for links that carry it after #).
 * - Closing clears #gs-connect and the one-shot arguments from the address bar, so a reload does not reopen it.
 * Every form inside posts to the existing admin-post handlers; this script never sends anything itself.
 * ES5, no jQuery, no configuration.
 */
(function () {
	'use strict';

	var modal = null;
	var dialog = null;
	var opener = null;
	var CODE = /^[A-HJ-NP-Z2-9]{6}$/;
	var ONE_SHOT = ['gs_connect', 'gs_connect_status', 'gs_connect_message', 'gs_disconnect_failed', 'gend_society_code'];
	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

	function closest(el, selector) {
		while (el && el.nodeType === 1) {
			if (el.matches ? el.matches(selector) : (el.msMatchesSelector && el.msMatchesSelector(selector))) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function visible(el) {
		// Content of a closed <details> (the pairing form) is not tabbable, whatever its box reports; its summary is.
		var details = closest(el.parentNode, 'details');
		while (details) {
			if (!details.open && !(el.tagName === 'SUMMARY' && el.parentNode === details)) {
				return false;
			}
			details = closest(details.parentNode, 'details');
		}
		return !!(el.offsetWidth || el.offsetHeight || (el.getClientRects && el.getClientRects().length));
	}

	function focusables() {
		var list = dialog.querySelectorAll(FOCUSABLE);
		var out = [];
		for (var i = 0; i < list.length; i++) {
			if (visible(list[i])) {
				out.push(list[i]);
			}
		}
		return out;
	}

	function isOpen() {
		return !!modal && modal.classList.contains('is-open');
	}

	function queryParam(name) {
		var q = window.location.search.replace(/^\?/, '').split('&');
		for (var i = 0; i < q.length; i++) {
			var kv = q[i].split('=');
			if (decodeURIComponent(kv[0] || '') === name) {
				try {
					return decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
				} catch (e) {
					return '';
				}
			}
		}
		return '';
	}

	function prefill() {
		var code = queryParam('gend_society_code');
		var field = modal.querySelector('input[name="pairing_code"]');
		if (!field || !CODE.test(code)) {
			return;
		}
		if (!field.value) {
			field.value = code;
		}
		var details = closest(field, 'details');
		if (details) {
			details.open = true;
		}
	}

	function firstFocus() {
		// With a prefilled code the next step is the consent box; otherwise the first control.
		var consent = modal.querySelector('details[open] input[name="gend_society_consent"]');
		if (consent && visible(consent)) {
			return consent;
		}
		var list = focusables();
		for (var i = 0; i < list.length; i++) {
			if (!list[i].hasAttribute('data-gs-connect-close')) {
				return list[i];
			}
		}
		return list.length ? list[0] : dialog;
	}

	function cleanUrl() {
		if (!window.history || !window.history.replaceState) {
			return;
		}
		var search = window.location.search.replace(/^\?/, '');
		var kept = [];
		if (search) {
			var parts = search.split('&');
			for (var i = 0; i < parts.length; i++) {
				var key = decodeURIComponent(parts[i].split('=')[0] || '');
				if (ONE_SHOT.indexOf(key) === -1) {
					kept.push(parts[i]);
				}
			}
		}
		var hash = window.location.hash === '#gs-connect' ? '' : window.location.hash;
		try {
			window.history.replaceState(window.history.state, '', window.location.pathname + (kept.length ? '?' + kept.join('&') : '') + hash);
		} catch (e) {}
	}

	function open(from) {
		if (!modal || isOpen()) {
			return;
		}
		opener = from || document.activeElement;
		modal.hidden = false;
		modal.removeAttribute('aria-hidden');
		modal.classList.add('is-open');
		document.body.classList.add('gs-connect-modal-open');
		prefill();
		var target = firstFocus();
		try {
			target.focus();
		} catch (e) {}
	}

	function close() {
		if (!isOpen()) {
			return;
		}
		modal.classList.remove('is-open');
		modal.hidden = true;
		modal.setAttribute('aria-hidden', 'true');
		document.body.classList.remove('gs-connect-modal-open');
		cleanUrl();
		var back = opener && document.body.contains(opener) ? opener : document.querySelector('button[data-gs-connect-open]');
		opener = null;
		if (back && back.focus) {
			try {
				back.focus();
			} catch (e) {}
		}
	}

	function trap(ev) {
		var list = focusables();
		if (!list.length) {
			ev.preventDefault();
			dialog.focus();
			return;
		}
		var first = list[0];
		var last = list[list.length - 1];
		var active = document.activeElement;
		if (ev.shiftKey && (active === first || !dialog.contains(active))) {
			ev.preventDefault();
			last.focus();
		} else if (!ev.shiftKey && (active === last || !dialog.contains(active))) {
			ev.preventDefault();
			first.focus();
		}
	}

	function init() {
		modal = document.querySelector('[data-gs-connect-modal]');
		if (!modal) {
			return;
		}
		dialog = modal.querySelector('[role="dialog"]') || modal;
		// The Integration Hub card uses backdrop-filter, which would make it the box of a position:fixed child.
		if (modal.parentNode !== document.body) {
			document.body.appendChild(modal);
		}

		document.addEventListener('click', function (ev) {
			var btn = closest(ev.target, '[data-gs-connect-open]');
			if (btn) {
				ev.preventDefault();
				open(btn);
				return;
			}
			if (isOpen() && closest(ev.target, '[data-gs-connect-close]')) {
				ev.preventDefault();
				close();
			}
		});

		document.addEventListener('keydown', function (ev) {
			if (!isOpen()) {
				return;
			}
			if (ev.key === 'Escape' || ev.key === 'Esc' || ev.keyCode === 27) {
				ev.preventDefault();
				close();
			} else if (ev.key === 'Tab' || ev.keyCode === 9) {
				trap(ev);
			}
		});

		window.addEventListener('hashchange', function () {
			if (window.location.hash === '#gs-connect') {
				open(null);
			}
		});

		if (window.location.hash === '#gs-connect' || modal.getAttribute('data-gs-connect-autoopen') === '1') {
			open(null);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
