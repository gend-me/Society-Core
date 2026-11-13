/**
 * gend.me — Google Drive picker (2026-11-13).
 *
 * Drive access uses the drive.file scope: gend.me can read only the files a member picks here
 * (Google records the pick against gend.me's Cloud project, so the server's stored Drive
 * connection can then read those files when a sequence runs). Nothing else in the member's
 * Drive is visible to gend.me.
 *
 *   window.gsDrivePicker.pick({ config, fileId, multiple }) → Promise<[{ id, name, url, mimeType, iconUrl }]>
 *     config  = the `picker` object from GET gs/v1/gdrive/status: { clientId, apiKey, appId }
 *     fileId  = optional: open the picker on one file (a pasted link) so the member confirms it
 *     resolves [] when the member cancels; rejects with a readable Error otherwise.
 *   window.gsDrivePicker.idFromUrl(url) → Drive file id from a Drive / Docs link ('' if none)
 *
 * The browser gets its own short-lived token straight from Google (Google Identity Services),
 * so no token ever passes through gend.me's pages or API.
 */
(function () {
	'use strict';
	if (window.gsDrivePicker) return;

	var SCOPE = 'https://www.googleapis.com/auth/drive.file';
	var loads = {};
	var pickerReady = null;
	var tok = '';
	var tokExp = 0;
	var tokClientId = '';

	function load(src) {
		if (!loads[src]) {
			loads[src] = new Promise(function (res, rej) {
				var s = document.createElement('script');
				s.src = src;
				s.async = true;
				s.onload = res;
				s.onerror = function () { delete loads[src]; rej(new Error('Could not load Google Drive. Check your connection and try again.')); };
				document.head.appendChild(s);
			});
		}
		return loads[src];
	}

	function ready() {
		if (!pickerReady) {
			pickerReady = Promise.all([load('https://accounts.google.com/gsi/client'), load('https://apis.google.com/js/api.js')])
				.then(function () { return new Promise(function (res) { window.gapi.load('picker', res); }); })
				.catch(function (e) { pickerReady = null; throw e; });
		}
		return pickerReady;
	}

	function token(cfg) {
		if (tok && tokClientId === cfg.clientId && Date.now() < tokExp) return Promise.resolve(tok);
		return new Promise(function (res, rej) {
			var client = window.google.accounts.oauth2.initTokenClient({
				client_id: cfg.clientId,
				scope: SCOPE,
				prompt: '',
				callback: function (r) {
					if (!r || r.error || !r.access_token) {
						rej(new Error((r && (r.error_description || r.error)) || 'Google sign-in didn’t finish.'));
						return;
					}
					tok = r.access_token;
					tokClientId = cfg.clientId;
					tokExp = Date.now() + (Math.max(120, parseInt(r.expires_in, 10) || 3600) - 60) * 1000;
					res(tok);
				},
				error_callback: function (e) {
					var t = e && e.type;
					rej(new Error(t === 'popup_closed' ? 'The Google window was closed before finishing.'
						: t === 'popup_failed_to_open' ? 'Your browser blocked the Google window — allow pop-ups for this site and try again.'
						: 'Google sign-in failed. Please try again.'));
				}
			});
			client.requestAccessToken();
		});
	}

	// The picker is appended to <body>; lift it above gend.me's popups and keep it inside an open
	// <dialog> (top layer) when there is one, so it is never hidden behind the popup it came from.
	function lift() {
		if (!document.getElementById('gs-drive-picker-css')) {
			var st = document.createElement('style');
			st.id = 'gs-drive-picker-css';
			st.textContent = '.picker-dialog-bg{z-index:2147483600!important}.picker-dialog{z-index:2147483601!important}';
			document.head.appendChild(st);
		}
		var n = 0;
		(function place() {
			n++;
			var els = document.querySelectorAll('.picker-dialog-bg, .picker-dialog');
			if (!els.length) { if (n < 20) setTimeout(place, 100); return; }
			var host = document.querySelector('dialog[open]') || document.body;
			Array.prototype.forEach.call(els, function (el) { if (el.parentElement !== host) host.appendChild(el); });
		})();
	}

	function pick(opts) {
		opts = opts || {};
		var cfg = opts.config || {};
		if (!cfg.clientId || !cfg.apiKey || !cfg.appId) {
			return Promise.reject(new Error('Google Drive isn’t fully set up on this site yet.'));
		}
		return ready().then(function () { return token(cfg); }).then(function (t) {
			return new Promise(function (res) {
				var P = window.google.picker;
				var view = new P.DocsView(P.ViewId.DOCS).setIncludeFolders(true).setSelectFolderEnabled(false).setMode(P.DocsViewMode.LIST);
				if (opts.fileId) view.setFileIds(String(opts.fileId));
				var b = new P.PickerBuilder()
					.addView(view)
					.setOAuthToken(t)
					.setDeveloperKey(cfg.apiKey)
					.setAppId(String(cfg.appId))
					.setOrigin(window.location.protocol + '//' + window.location.host)
					.setTitle(opts.fileId ? 'Confirm this file for gend.me' : 'Choose files for gend.me')
					.setCallback(function (d) {
						if (d[P.Response.ACTION] === P.Action.PICKED) {
							res((d[P.Response.DOCUMENTS] || []).map(function (x) {
								return { id: x[P.Document.ID], name: x[P.Document.NAME] || '', url: x[P.Document.URL] || '', mimeType: x[P.Document.MIME_TYPE] || '', iconUrl: x[P.Document.ICON_URL] || '' };
							}));
						} else if (d[P.Response.ACTION] === P.Action.CANCEL) {
							res([]);
						}
					});
				if (opts.multiple !== false && !opts.fileId) b.enableFeature(P.Feature.MULTISELECT_ENABLED);
				b.build().setVisible(true);
				lift();
			});
		});
	}

	function idFromUrl(u) {
		u = String(u || '').trim();
		var m = u.match(/\/d\/([a-zA-Z0-9_-]{10,})/) || u.match(/[?&]id=([a-zA-Z0-9_-]{10,})/);
		if (m) return m[1];
		return /^[a-zA-Z0-9_-]{20,}$/.test(u) ? u : '';
	}

	window.gsDrivePicker = { pick: pick, idFromUrl: idFromUrl };
})();
