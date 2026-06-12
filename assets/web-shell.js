/*
 * GenD Web Shell SPA — vanilla JS counterpart to the desktop app's
 * renderer. Loads against /web-shell/ on gend.me; expects the
 * window.__WS_BOOT__ payload printed by the PHP page renderer.
 *
 * Tabs:
 *   sites       — populated from /gs/v1/web-shell/sites
 *   terminal    — xterm.js attached to a placeholder pump that prints
 *                 the Phase-22 contract until the WebSocket backend
 *                 ships; the prompt is local-only so the user can try
 *                 the keyboard + voice flow now
 *   gas-station — populated from /gs/v1/web-shell/groups (links out to
 *                 each group's Compute Gas tab)
 *   davinci     — Web Speech API + wake-word filter; mirror of the
 *                 desktop hook. Voice commands switch tabs too.
 */

(function () {
    var boot = window.__WS_BOOT__ || {};
    if (!boot.restRoot) {
        console.warn('[web-shell] missing boot payload');
        return;
    }

    var root = document.body;
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-ws-tab]'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-ws-panel]'));

    function setTab(name) {
        tabs.forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-ws-tab') === name); });
        panels.forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-ws-panel') === name); });
        try { history.replaceState({}, '', '/web-shell/' + (name === 'sites' ? '' : name + '/')); } catch (e) {}
        if (name === 'terminal' && term && fitAddon) {
            // xterm needs a re-fit when its container becomes visible
            // for the first time so the dimensions match the panel.
            setTimeout(function () { try { fitAddon.fit(); } catch (e) {} }, 50);
        }
    }
    tabs.forEach(function (b) { b.addEventListener('click', function () { setTab(b.getAttribute('data-ws-tab')); }); });

    /* ───────────── Sites tab ───────────── */
    function loadSites() {
        var loadingEl = document.getElementById('ws-sites-loading');
        var gridEl    = document.getElementById('ws-sites-grid');
        fetch(boot.restRoot.replace(/\/+$/, '') + boot.sitesPath, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                loadingEl.hidden = true;
                gridEl.hidden = false;
                var sites = (j && j.sites) || [];
                if (sites.length === 0) {
                    gridEl.innerHTML = '<div class="ws-empty">No sites linked to your account yet. Provision one from gend.me first.</div>';
                    return;
                }
                gridEl.innerHTML = sites.map(function (s) {
                    var logo = s.logo
                        ? '<img class="ws-site-logo" src="' + esc(s.logo) + '" alt="">'
                        : '<div class="ws-site-logo" style="display:flex;align-items:center;justify-content:center;font-weight:900;color:var(--accent);">'
                            + esc((s.title || 'S').charAt(0).toUpperCase()) + '</div>';
                    return ''
                        + '<div class="ws-site" data-site-id="' + esc(String(s.site_id || 0)) + '" data-site-host="' + esc(s.host || '') + '">'
                        +   '<div class="ws-site-head">'
                        +     logo
                        +     '<div style="min-width:0;flex:1;">'
                        +       '<div class="ws-site-title">' + esc(s.title) + '</div>'
                        +       (s.host ? '<div class="ws-site-host">' + esc(s.host) + '</div>' : '')
                        +     '</div>'
                        +     '<div class="ws-site-menu-wrap" data-site-menu-wrap>'
                        +       '<button class="ws-site-menu-btn" data-site-menu-btn aria-label="Actions" title="Site actions">⋯</button>'
                        +     '</div>'
                        +   '</div>'
                        +   '<div class="ws-site-meta">'
                        +     (s.status ? '<span>' + esc(s.status) + '</span>' : '')
                        +     (s.site_id ? '<span>site #' + esc(String(s.site_id)) + '</span>' : '')
                        +   '</div>'
                        +   '<div class="ws-site-actions">'
                        +     (s.url       ? '<a class="ws-btn ws-btn--primary" href="' + esc(s.url) + '" target="_blank" rel="noopener">Open</a>' : '')
                        +     (s.admin_url ? '<a class="ws-btn ws-btn--ghost"   href="' + esc(s.admin_url) + '" target="_blank" rel="noopener">wp-admin</a>' : '')
                        +   '</div>'
                        + '</div>';
                }).join('');
                wireSiteMenus(gridEl);
            })
            .catch(function (e) {
                loadingEl.textContent = 'Failed to load sites: ' + (e && e.message ? e.message : e);
            });
    }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    /* ───────── shared modal helpers ───────── */
    var modalEl   = document.getElementById('ws-modal');
    var modalTtl  = document.getElementById('ws-modal-title');
    var modalBody = document.getElementById('ws-modal-body');
    var modalClose = document.getElementById('ws-modal-close');
    function openModal(title, html) {
        modalTtl.textContent = title;
        modalBody.innerHTML = html;
        modalEl.hidden = false;
    }
    function closeModal() { modalEl.hidden = true; }
    if (modalClose) modalClose.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modalEl.hidden) closeModal(); });
    modalEl.addEventListener('click', function (e) { if (e.target === modalEl) closeModal(); });

    /* ───────── site actions ───────── */
    function sitePath(siteId, action) {
        return boot.restRoot.replace(/\/+$/, '')
            + boot.sitePath.replace('{id}', siteId) + '/' + action;
    }
    function apiSite(method, siteId, action) {
        return fetch(sitePath(siteId, action), {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); });
    }

    function showSiteStatus(siteId, host, title) {
        openModal('Site Status — ' + (title || ('#' + siteId)),
            '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">Loading status…</div>');
        apiSite('GET', siteId, 'status').then(function (j) {
            if (!j || j.code) {
                openModal('Status', '<div class="ws-banner is-err">' + esc(j && (j.message || j.code) || 'failed') + '</div>');
                return;
            }
            var probe = j.probe || {};
            var up = probe.reachable
                ? '<span class="ws-pill is-up">up</span>'
                : '<span class="ws-pill is-down">down</span>';
            var probeLine = probe.http_status
                ? ('HTTP ' + probe.http_status + ' · ' + (probe.ms || '?') + ' ms')
                : (probe.ms != null ? ('no response (' + probe.ms + ' ms)') : 'not probed');
            openModal('Site Status — ' + esc(j.title || ('#' + siteId)),
                '<dl>'
                + '<dt>Status</dt><dd>' + up + ' &nbsp;' + esc(probeLine) + '</dd>'
                + '<dt>Site ID</dt><dd>' + esc(String(j.site_id)) + '</dd>'
                + (j.host       ? '<dt>Host</dt><dd>' + esc(j.host) + '</dd>' : '')
                + (j.install_id ? '<dt>Install ID</dt><dd>' + esc(j.install_id) + '</dd>' : '')
                + (j.is_staging ? '<dt>Staging of</dt><dd>site #' + esc(String(j.staging_of)) + '</dd>'
                                : (j.staging_id ? '<dt>Has staging</dt><dd>site #' + esc(String(j.staging_id)) + '</dd>' : ''))
                + '<dt>Checked</dt><dd>' + esc(j.checked_at) + '</dd>'
                + '</dl>'
            );
        }).catch(function (e) {
            openModal('Status', '<div class="ws-banner is-err">' + esc(e && e.message || 'network error') + '</div>');
        });
    }

    function runAction(siteId, action, method, label, confirmMsg) {
        if (confirmMsg && !window.confirm(confirmMsg)) return;
        openModal(label, '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">' + esc(label) + '…</div>');
        apiSite(method, siteId, action).then(function (j) {
            var cls = 'is-ok';
            var body = '';
            if (!j || j.code) {
                cls = 'is-err';
                body = esc((j && (j.message || j.code)) || 'failed');
            } else if (j.status === 'pending_integration') {
                cls = ''; // amber default
                body = esc(j.message || 'queued')
                    + (j.integration ? '<br><br><strong>Integration:</strong> ' + esc(j.integration) : '')
                    + (j.next_step && j.next_step.description ? '<br><br><strong>Next step:</strong> ' + esc(j.next_step.description) : '');
            } else if (j.status === 'queued') {
                cls = 'is-ok';
                body = esc(j.message || 'Queued.') + '<br><br><strong>Queued at:</strong> ' + esc(j.queue_at || '');
            } else if (j.status === 'exists') {
                cls = '';
                body = esc(j.message || 'Already exists.') + '<br><br><strong>Staging site #:</strong> ' + esc(String(j.staging_site_id));
            } else if (j.deleted !== undefined) {
                body = j.deleted
                    ? ('Staging site #' + esc(String(j.staging_id)) + ' deleted.')
                    : 'Delete failed — staging may have already been removed.';
                if (!j.deleted) cls = 'is-err';
                if (j.deleted) setTimeout(loadSites, 500);
            } else {
                body = '<pre style="margin:0;font-size:11px;white-space:pre-wrap;">' + esc(JSON.stringify(j, null, 2)) + '</pre>';
            }
            openModal(label, '<div class="ws-banner ' + cls + '">' + body + '</div>');
        }).catch(function (e) {
            openModal(label, '<div class="ws-banner is-err">' + esc(e && e.message || 'network error') + '</div>');
        });
    }

    function openTerminalInSite(siteId, host) {
        setTab('terminal');
        // Wait a beat for the xterm to attach + the prompt to land,
        // then type "cd ~/sites/<id>" so the user lands in the right
        // working directory. In offline stub mode it just echoes.
        setTimeout(function () {
            if (!term) return;
            var line = 'cd ~/sites/' + siteId + '   # ' + (host || '');
            for (var i = 0; i < line.length; i++) term.write(line.charAt(i));
            term.write('\r\n');
            runLocalLine(line);
            writePrompt();
        }, 200);
    }

    function buildSiteMenu(siteId, host, title) {
        var menu = document.createElement('div');
        menu.className = 'ws-site-menu';
        var items = [
            { label: 'View status',               kind: 'status'   },
            { label: 'Open terminal in this site', kind: 'terminal' },
            { divider: true },
            { label: 'Clone to staging',          kind: 'clone'    },
            { label: 'Promote staging → live',    kind: 'promote'  },
            { label: 'Restart container',         kind: 'restart'  },
            { divider: true },
            { label: 'Tear down staging',         kind: 'staging-del', danger: true }
        ];
        menu.innerHTML = items.map(function (it) {
            if (it.divider) return '<div class="ws-site-menu-divider"></div>';
            return '<button class="ws-site-menu-item' + (it.danger ? ' is-danger' : '') + '" data-action="' + it.kind + '">'
                + esc(it.label) + '</button>';
        }).join('');
        menu.addEventListener('click', function (e) {
            var b = e.target.closest('[data-action]');
            if (!b) return;
            var kind = b.getAttribute('data-action');
            menu.classList.remove('is-open');
            if (kind === 'status')      { showSiteStatus(siteId, host, title); }
            if (kind === 'terminal')    { openTerminalInSite(siteId, host); }
            if (kind === 'clone')       { runAction(siteId, 'clone',   'POST',   'Clone to staging'); }
            if (kind === 'promote')     { runAction(siteId, 'promote', 'POST',   'Promote staging → live', 'Promote staging → live for site #' + siteId + '?'); }
            if (kind === 'restart')     { runAction(siteId, 'restart', 'POST',   'Restart container'); }
            if (kind === 'staging-del') { runAction(siteId, 'staging', 'DELETE', 'Tear down staging', 'Delete the staging copy for site #' + siteId + '?'); }
        });
        return menu;
    }

    function wireSiteMenus(scope) {
        // Click-outside closes any open menu.
        document.addEventListener('click', function (e) {
            scope.querySelectorAll('.ws-site-menu.is-open').forEach(function (m) {
                if (!m.contains(e.target) && !e.target.closest('[data-site-menu-btn]')) {
                    m.classList.remove('is-open');
                }
            });
        });
        scope.querySelectorAll('.ws-site').forEach(function (card) {
            var btn = card.querySelector('[data-site-menu-btn]');
            var wrap = card.querySelector('[data-site-menu-wrap]');
            var siteId = parseInt(card.getAttribute('data-site-id'), 10) || 0;
            var host   = card.getAttribute('data-site-host') || '';
            var title  = (card.querySelector('.ws-site-title') || {}).textContent || '';
            if (!btn || !wrap || !siteId) return;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                // Close other open menus, then open ours.
                scope.querySelectorAll('.ws-site-menu.is-open').forEach(function (m) { if (m !== wrap.querySelector('.ws-site-menu')) m.classList.remove('is-open'); });
                var existing = wrap.querySelector('.ws-site-menu');
                if (existing) { existing.classList.toggle('is-open'); return; }
                var menu = buildSiteMenu(siteId, host, title);
                wrap.appendChild(menu);
                menu.classList.add('is-open');
            });
        });
    }

    loadSites();

    /* ───────────── Gas Station tab ───────────── */
    function loadGroups() {
        var loadingEl = document.getElementById('ws-gas-loading');
        var listEl    = document.getElementById('ws-gas-list');
        fetch(boot.restRoot.replace(/\/+$/, '') + boot.groupsPath, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                loadingEl.hidden = true;
                listEl.hidden = false;
                var groups = (j && j.groups) || [];
                if (groups.length === 0) {
                    listEl.innerHTML = '<div class="ws-empty">You\'re not an admin of any groups with a Compute Gas tab.</div>';
                    return;
                }
                listEl.innerHTML = groups.map(function (g) {
                    return ''
                        + '<div class="ws-gas-row">'
                        +   '<div class="ws-gas-row-name">' + esc(g.name) + '</div>'
                        +   '<a href="' + esc(g.gas_url) + '" target="_blank" rel="noopener">Open Compute Gas →</a>'
                        + '</div>';
                }).join('');
            })
            .catch(function (e) {
                loadingEl.textContent = 'Failed: ' + (e && e.message ? e.message : e);
            });
    }
    loadGroups();

    /* ───────────── Terminal tab (xterm.js + Phase-22 stub) ─────────────
       The terminal renders + accepts keystrokes locally even before the
       PTY backend ships, so the user can sanity-check the wiring + try
       the Davinci voice path. We hit /gs/v1/web-shell/terminal once
       to fetch the contract status; if backend says "pending" we make
       the prompt purely cosmetic. */
    var term = null, fitAddon = null;
    var termStatusEl = document.getElementById('ws-term-status');
    var reconnectBtn = document.getElementById('ws-term-reconnect');

    function setTermStatus(text, kind) {
        if (!termStatusEl) return;
        termStatusEl.textContent = text;
        termStatusEl.className = 'ws-terminal-status'
            + (kind === 'ok'   ? ' is-connected' : '')
            + (kind === 'err'  ? ' is-error'     : '');
    }

    function initTerminal() {
        if (typeof Terminal === 'undefined') {
            setTermStatus('xterm unavailable', 'err');
            return;
        }
        term = new Terminal({
            fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
            fontSize: 13,
            theme: { background: '#000000', foreground: '#e2e8f0', cursor: '#a78bfa' },
            cursorBlink: true,
            convertEol: true,
            scrollback: 4000
        });
        if (window.FitAddon && window.FitAddon.FitAddon) {
            fitAddon = new window.FitAddon.FitAddon();
            term.loadAddon(fitAddon);
        }
        term.open(document.getElementById('ws-xterm'));
        if (fitAddon) try { fitAddon.fit(); } catch (e) {}
        window.addEventListener('resize', function () { if (fitAddon) try { fitAddon.fit(); } catch (e) {} });

        connectTerminalBackend();

        // Local echo so the terminal feels alive even without backend.
        // Every line is shipped to the backend handler (if connected)
        // or echoed locally with a "(offline)" tag.
        var lineBuf = '';
        term.onData(function (data) {
            for (var i = 0; i < data.length; i++) {
                var ch = data[i];
                if (ch === '\r') {
                    term.write('\r\n');
                    runLocalLine(lineBuf);
                    lineBuf = '';
                    writePrompt();
                } else if (ch === '\x7f' || ch === '\b') {
                    if (lineBuf.length > 0) {
                        lineBuf = lineBuf.slice(0, -1);
                        term.write('\b \b');
                    }
                } else if (ch >= ' ') {
                    lineBuf += ch;
                    term.write(ch);
                }
            }
        });
    }
    function writePrompt() {
        if (!term) return;
        term.write('\x1b[1;35m➜\x1b[0m \x1b[1;36mweb-shell\x1b[0m \x1b[2m(offline)\x1b[0m $ ');
    }
    function runLocalLine(line) {
        var cmd = (line || '').trim();
        if (!cmd) return;
        if (cmd === 'help' || cmd === '?') {
            term.write('\x1b[1mAvailable in stub mode:\x1b[0m\r\n');
            term.write('  help     show this help\r\n');
            term.write('  clear    clear the screen\r\n');
            term.write('  status   show backend state\r\n');
            term.write('  echo X   echo X back\r\n');
            term.write('\r\n\x1b[2mFull bash + git + kubectl + gcloud + npm arrive once Phase 22 (per-user ephemeral container) ships.\x1b[0m\r\n');
            return;
        }
        if (cmd === 'clear') { term.clear(); return; }
        if (cmd === 'status') {
            term.write('phase 22 backend: \x1b[33mpending\x1b[0m — see https://gend.me/web-shell/davinci for the design doc.\r\n');
            return;
        }
        if (cmd.indexOf('echo ') === 0) { term.write(cmd.slice(5) + '\r\n'); return; }
        term.write('\x1b[31moffline:\x1b[0m unknown command "' + cmd + '" — try \x1b[36mhelp\x1b[0m.\r\n');
    }

    function connectTerminalBackend() {
        setTermStatus('checking backend…');
        fetch(boot.restRoot.replace(/\/+$/, '') + boot.termPath, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.status === 'pending') {
                    setTermStatus('phase 22 — pending', 'err');
                    if (term) {
                        term.write('\r\n\x1b[2m── GenD Web Shell terminal ──\x1b[0m\r\n');
                        term.write('Backend status: \x1b[33m' + (j.message || 'pending') + '\x1b[0m\r\n');
                        term.write('Isolation when ready: \x1b[36m' + (j.isolation || 'per-user-ephemeral-container') + '\x1b[0m\r\n');
                        term.write('Capabilities: \x1b[36m' + ((j.capabilities || []).join(', ') || '—') + '\x1b[0m\r\n\r\n');
                        term.write('You can still try the prompt — type \x1b[36mhelp\x1b[0m for offline commands.\r\n\r\n');
                        writePrompt();
                    }
                } else if (j && j.ws_url && j.token) {
                    setTermStatus('connecting…');
                    openWebSocket(j);
                }
            })
            .catch(function () {
                setTermStatus('backend unreachable', 'err');
                if (term) {
                    term.write('\r\n\x1b[31mCould not reach the terminal backend.\x1b[0m Local-only mode.\r\n');
                    writePrompt();
                }
            });
    }
    if (reconnectBtn) reconnectBtn.addEventListener('click', connectTerminalBackend);

    /* ───────────── gcloud connect/disconnect ───────────── */
    var gcPill   = document.getElementById('ws-gcloud-pill');
    var gcPillTx = document.getElementById('ws-gcloud-pill-text');
    var gcAction = document.getElementById('ws-gcloud-action');
    function setGcPill(state, text) {
        if (gcPill) gcPill.setAttribute('data-state', state);
        if (gcPillTx) gcPillTx.textContent = text;
    }
    function refreshGcloudStatus() {
        fetch(boot.restRoot.replace(/\/+$/, '') + boot.gcloudStatus, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j) return;
                if (!j.has_oauth_client || !j.has_encryption_key) {
                    setGcPill('missing-config', 'gcloud: needs hub config');
                    if (gcAction) gcAction.hidden = true;
                    return;
                }
                if (j.connected) {
                    setGcPill('connected', 'gcloud: ' + (j.google_email || 'connected'));
                    if (gcAction) { gcAction.hidden = false; gcAction.textContent = 'Disconnect gcloud'; gcAction.dataset.gcMode = 'off'; }
                } else {
                    setGcPill('unknown', 'gcloud: not connected');
                    if (gcAction) { gcAction.hidden = false; gcAction.textContent = 'Connect gcloud'; gcAction.dataset.gcMode = 'on'; }
                }
            })
            .catch(function () { setGcPill('unknown', 'gcloud: status error'); });
    }
    if (gcAction) {
        gcAction.addEventListener('click', function () {
            if (gcAction.dataset.gcMode === 'off') {
                gcAction.disabled = true;
                fetch(boot.restRoot.replace(/\/+$/, '') + boot.gcloudOff, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' }
                }).then(function () { gcAction.disabled = false; refreshGcloudStatus(); });
                return;
            }
            // Connect — pop a tab to Google's consent screen. The
            // callback redirects back to /web-shell/terminal/?gcloud_connected=1
            // which our query-arg watcher catches.
            fetch(boot.restRoot.replace(/\/+$/, '') + boot.gcloudStart, {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' }
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j && j.authorize_url) window.location.href = j.authorize_url;
            });
        });
    }
    refreshGcloudStatus();
    // Catch the OAuth callback bounce.
    if (location.search.indexOf('gcloud_connected=1') !== -1) {
        setTab('terminal');
        try { history.replaceState({}, '', '/web-shell/terminal/'); } catch (e) {}
        // Tiny delay so the SPA finishes mounting before we re-query.
        setTimeout(refreshGcloudStatus, 400);
    }

    /**
     * Live WebSocket bridge to the per-user ephemeral container PTY.
     * Called when /gs/v1/web-shell/terminal returns status=ready with
     * a one-shot token. Replaces the local-only prompt with a real
     * shell connection — stdin / stdout / stderr stream as raw bytes;
     * resize events as {type:'resize',cols,rows} control frames.
     */
    var activeWs = null;
    var disposeData = null;
    function openWebSocket(ready) {
        if (activeWs) try { activeWs.close(); } catch (e) {}
        if (disposeData) try { disposeData.dispose(); } catch (e) {}
        var ws = new WebSocket(ready.ws_url + '?token=' + encodeURIComponent(ready.token));
        ws.binaryType = 'arraybuffer';
        activeWs = ws;

        ws.onopen = function () {
            setTermStatus('connected · ' + (ready.container_id || 'pod'), 'ok');
            // Send initial resize so the remote PTY matches the xterm
            // dimensions on first paint.
            sendResize();
        };
        ws.onmessage = function (ev) {
            // Server frames are raw bytes — write straight into xterm.
            if (typeof ev.data === 'string') {
                term.write(ev.data);
            } else {
                term.write(new Uint8Array(ev.data));
            }
        };
        ws.onerror = function () { setTermStatus('connection error', 'err'); };
        ws.onclose = function (ev) {
            setTermStatus('disconnected (' + (ev.code || '0') + ')', 'err');
            term.write('\r\n\x1b[31m── connection closed ──\x1b[0m\r\n');
            term.write('\x1b[2mClick Reconnect to spin up a fresh session.\x1b[0m\r\n');
            activeWs = null;
            if (disposeData) { try { disposeData.dispose(); } catch (e) {} disposeData = null; }
        };
        // term.onData returns an IDisposable in xterm 5.x — keep the
        // handle so we can tear the listener down on reconnect.
        disposeData = term.onData(function (data) {
            if (activeWs && activeWs.readyState === 1) activeWs.send(data);
        });
        // Resize control frame on window resize while connected.
        var sendResize = function () {
            if (!fitAddon || !activeWs || activeWs.readyState !== 1) return;
            try { fitAddon.fit(); } catch (e) {}
            try {
                activeWs.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows }));
            } catch (e) {}
        };
        window.addEventListener('resize', sendResize);
    }

    initTerminal();

    /* ───────────── Davinci voice tab ─────────────
       Same wake-word + shortcut grammar as the desktop hook, but routes
       commands either to the terminal (if active) or treats them as tab
       switches ("davinci sites", "davinci terminal", "davinci gas
       station"). */
    var Recog = window.SpeechRecognition || window.webkitSpeechRecognition;
    var davToggle  = document.getElementById('ws-davinci-toggle');
    var davStatus  = document.getElementById('ws-davinci-status');
    var davScript  = document.getElementById('ws-davinci-transcript');
    var davRecog = null;
    var davLog = [];

    var TAB_ALIASES = {
        'sites': 'sites', 'site': 'sites',
        'terminal': 'terminal', 'shell': 'terminal', 'console': 'terminal',
        'chat': 'chat', 'claude': 'chat',
        'gas station': 'gas-station', 'gas': 'gas-station',
        'davinci': 'davinci'
    };
    var WAKE_RE = [
        /^\s*da\s*vinci[,.\s]*/i,
        /^\s*divinci[,.\s]*/i,
        /^\s*davenchi[,.\s]*/i,
        /^\s*hey\s+davinci[,.\s]*/i
    ];

    function stripWake(text) {
        if (!text) return null;
        for (var i = 0; i < WAKE_RE.length; i++) {
            if (WAKE_RE[i].test(text)) return text.replace(WAKE_RE[i], '').trim();
        }
        return null;
    }
    function setDavStatus(text, on) {
        davStatus.innerHTML = '<span class="dot"></span> ' + esc(text);
        davStatus.classList.toggle('is-on', !!on);
    }
    function appendTranscript(line) {
        davLog.push(line);
        if (davLog.length > 12) davLog = davLog.slice(-12);
        davScript.textContent = davLog.join('\n');
    }

    function dispatchCommand(rest) {
        var key = rest.replace(/[,.!?]/g, '').trim().toLowerCase();
        // Tab-switch grammar
        if (TAB_ALIASES[key]) { setTab(TAB_ALIASES[key]); appendTranscript('→ switched to ' + TAB_ALIASES[key]); return; }
        if (/^(switch|go) to /.test(key)) {
            var rest2 = key.replace(/^(switch|go) to /, '');
            if (TAB_ALIASES[rest2]) { setTab(TAB_ALIASES[rest2]); appendTranscript('→ switched to ' + TAB_ALIASES[rest2]); return; }
        }
        // "ask <X>" → switch to Chat tab + send X as a Claude message
        if (/^ask /i.test(rest)) {
            var question = rest.replace(/^ask\s+/i, '').trim();
            if (question && typeof window.__wsDavinciAsk === 'function') {
                window.__wsDavinciAsk(question);
                appendTranscript('🤖 asking Claude: ' + question);
                return;
            }
        }
        if (key === 'send' || key === 'submit') { appendTranscript('→ send (no composer in this tab)'); return; }
        if (key === 'clear') {
            if (document.querySelector('[data-ws-panel="terminal"]').classList.contains('is-active') && term) {
                term.clear(); appendTranscript('→ cleared terminal');
            } else {
                davLog = []; davScript.textContent = '';
            }
            return;
        }
        if (key.indexOf('run ') === 0 && term) {
            var line = rest.replace(/^run\s+/i, '');
            // Type the command into the terminal as if the user typed
            // it, then submit it. Calls runLocalLine since we're in
            // stub mode.
            for (var i = 0; i < line.length; i++) term.write(line.charAt(i));
            term.write('\r\n');
            runLocalLine(line);
            writePrompt();
            appendTranscript('▶ ' + line);
            return;
        }
        // Default: just append to the transcript so the user sees what
        // Davinci heard. When we have the Claude integration (Phase 25)
        // this is where the message would get sent to the chat.
        appendTranscript('“' + rest + '”');
    }

    function davStart() {
        if (!Recog) { setDavStatus('not supported in this browser', false); return; }
        if (davRecog) return;
        try {
            var r = new Recog();
            r.continuous = true;
            r.interimResults = true;
            r.lang = navigator.language || 'en-US';
            r.onresult = function (ev) {
                for (var i = ev.resultIndex; i < ev.results.length; i++) {
                    var res = ev.results[i];
                    if (!res.isFinal) continue;
                    var text = (res[0] && res[0].transcript) || '';
                    var rest = stripWake(text);
                    if (rest !== null && rest !== '') dispatchCommand(rest);
                }
            };
            r.onerror = function (ev) {
                if (ev.error === 'no-speech' || ev.error === 'aborted') return;
                setDavStatus('error: ' + ev.error, false);
            };
            r.onend = function () {
                // Auto-restart while the user thinks we're still on.
                if (davRecog === r) { try { r.start(); } catch (e) {} }
            };
            r.start();
            davRecog = r;
            setDavStatus('listening — say “Davinci, …”', true);
            davToggle.textContent = '⏹ Stop Listening';
        } catch (e) {
            setDavStatus('start failed: ' + e.message, false);
        }
    }
    /* ───────────── Chat tab (Anthropic streaming) ───────────── */
    var chatSetupEl  = document.getElementById('ws-chat-setup');
    var chatConvEl   = document.getElementById('ws-chat-conv');
    var chatKeyForm  = document.getElementById('ws-chat-key-form');
    var chatKeyInput = document.getElementById('ws-chat-key-input');
    var chatKeyModel = document.getElementById('ws-chat-key-model');
    var chatKeyStat  = document.getElementById('ws-chat-key-status');
    var chatMsgsEl   = document.getElementById('ws-chat-messages');
    var chatComposer = document.getElementById('ws-chat-composer');
    var chatInputEl  = document.getElementById('ws-chat-input');
    var chatSendBtn  = document.getElementById('ws-chat-send');
    var chatStopBtn  = document.getElementById('ws-chat-stop');
    var chatResetBtn = document.getElementById('ws-chat-reset');
    var chatOffBtn   = document.getElementById('ws-chat-disconnect');
    var chatModelPill= document.getElementById('ws-chat-model-pill');
    var chatModel    = '';
    var chatHistory  = [];        // [{role, content}]
    var chatAbort    = null;       // AbortController for the in-flight stream

    function chatRender() {
        chatMsgsEl.innerHTML = chatHistory.map(function (m, i) {
            var role = m.role === 'user' ? 'user' : (m.role === 'assistant' ? 'assistant' : 'error');
            var caret = (i === chatHistory.length - 1 && m.streaming) ? '<span class="ws-chat-cursor"></span>' : '';
            var body = renderChatContent(m.content) + caret;
            return '<div class="ws-chat-msg" data-role="' + role + '">'
                + '<div class="ws-chat-msg-role">' + role + '</div>'
                + '<div class="ws-chat-msg-body">' + body + '</div>'
                + '</div>';
        }).join('');
        chatMsgsEl.scrollTop = chatMsgsEl.scrollHeight;
    }
    function renderChatContent(content) {
        if (typeof content === 'string') return esc(content);
        if (!Array.isArray(content)) return esc(JSON.stringify(content));
        return content.map(function (b) {
            if (b.type === 'text')      return esc(b.text || '');
            if (b.type === 'tool_use')  {
                return '<div class="ws-chat-tool">'
                    + '<div class="ws-chat-tool-head">🔧 ' + esc(b.name) + '</div>'
                    + '<pre class="ws-chat-tool-input">' + esc(JSON.stringify(b.input || {}, null, 2)) + '</pre>'
                    + '</div>';
            }
            if (b.type === 'tool_result') {
                var out = '';
                if (typeof b.content === 'string') out = b.content;
                else if (Array.isArray(b.content)) out = b.content.map(function (x) { return x.text || JSON.stringify(x); }).join('\n');
                var cls = b.is_error ? 'is-error' : '';
                return '<div class="ws-chat-tool-result ' + cls + '">'
                    + '<div class="ws-chat-tool-head">↳ result' + (b.is_error ? ' (error)' : '') + '</div>'
                    + '<pre>' + esc(out.length > 4000 ? out.slice(0, 4000) + '\n…[truncated]' : out) + '</pre>'
                    + '</div>';
            }
            return esc(JSON.stringify(b));
        }).join('');
    }

    /* ───────── tool registry ─────────
       Anthropic tool definitions sent with each chat turn. Add new
       tools here + matching dispatch in runTool() and they're live. */
    var CHAT_TOOLS = [
        {
            name: 'shell_exec',
            description: 'Run a bash command in the user\'s ephemeral container on shell.gend.me. Returns stdout, stderr, exit code. 30s wall-clock cap, 1MB output cap. Working directory persists per-session — use cd or absolute paths.',
            input_schema: {
                type: 'object',
                properties: { command: { type: 'string', description: 'The bash command line to execute.' } },
                required: ['command']
            }
        },
        {
            name: 'read_file',
            description: 'Read a file from the user\'s ephemeral container. 256KB cap; use shell_exec with sed/tail for bigger files.',
            input_schema: {
                type: 'object',
                properties: { path: { type: 'string', description: 'Absolute path inside the container.' } },
                required: ['path']
            }
        },
        {
            name: 'write_file',
            description: 'Create or overwrite a file in the user\'s container. Auto-creates parent directories. 1MB cap. For incremental edits, prefer reading + rewriting the whole file (binary-safe via base64 on the wire).',
            input_schema: {
                type: 'object',
                properties: {
                    path:    { type: 'string', description: 'Absolute path inside the container.' },
                    content: { type: 'string', description: 'Full file contents.' }
                },
                required: ['path', 'content']
            }
        },
        {
            name: 'git_status',
            description: 'Run `git status --short --branch` in the container. Use this to see what\'s modified before diffs / commits.',
            input_schema: {
                type: 'object',
                properties: { cwd: { type: 'string', description: 'Repo directory. Default /workspace.' } }
            }
        },
        {
            name: 'git_diff',
            description: 'Run `git diff` (working tree) or `git diff --cached` (staged). Optionally narrow to paths. Use after git_status to inspect changes.',
            input_schema: {
                type: 'object',
                properties: {
                    cwd:    { type: 'string',  description: 'Repo directory. Default /workspace.' },
                    staged: { type: 'boolean', description: 'true → show staged changes only (--cached).' },
                    paths:  { type: 'array',   items: { type: 'string' }, description: 'Limit diff to these paths.' }
                }
            }
        },
        {
            name: 'git_commit',
            description: 'Commit changes. Auto-sets user.email/name from the gend.me account. add_all defaults to true (runs `git add -A` first).',
            input_schema: {
                type: 'object',
                properties: {
                    cwd:     { type: 'string',  description: 'Repo directory. Default /workspace.' },
                    message: { type: 'string',  description: 'Commit message.' },
                    add_all: { type: 'boolean', description: 'Stage all changes before committing. Default true.' }
                },
                required: ['message']
            }
        }
    ];

    function runTool(name, input) {
        var endpoint = '';
        var body = {};
        if (name === 'shell_exec') { endpoint = '/wp-json/gs/v1/web-shell/tools/shell_exec'; body = { command: input.command || '' }; }
        else if (name === 'read_file')  { endpoint = '/wp-json/gs/v1/web-shell/tools/read_file';  body = { path: input.path || '' }; }
        else if (name === 'write_file') { endpoint = '/wp-json/gs/v1/web-shell/tools/write_file'; body = { path: input.path || '', content: input.content || '' }; }
        else if (name === 'git_status') { endpoint = '/wp-json/gs/v1/web-shell/tools/git_status'; body = { cwd: input.cwd || '/workspace' }; }
        else if (name === 'git_diff')   { endpoint = '/wp-json/gs/v1/web-shell/tools/git_diff';   body = { cwd: input.cwd || '/workspace', staged: !!input.staged, paths: input.paths || [] }; }
        else if (name === 'git_commit') { endpoint = '/wp-json/gs/v1/web-shell/tools/git_commit'; body = { cwd: input.cwd || '/workspace', message: input.message || '', add_all: input.add_all !== false }; }
        else return Promise.resolve({ ok: false, error: 'unknown-tool' });
        return fetch(boot.restRoot.replace(/\/+$/, '') + endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': boot.nonce, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); }).catch(function (e) { return { ok:false, error:e.message }; });
    }

    function formatToolResult(name, j) {
        if (!j || j.ok === false) {
            return { content: 'Tool failed: ' + ((j && (j.error || j.message)) || 'unknown'), is_error: true };
        }
        // shell_exec + git_* all return the same shape (they're all
        // shell_exec underneath on the WP side).
        if (name === 'shell_exec' || name === 'git_status' || name === 'git_diff' || name === 'git_commit') {
            var s = '';
            if (j.stdout) s += j.stdout;
            if (j.stderr) s += (s ? '\n' : '') + '[stderr]\n' + j.stderr;
            s += '\n[exit ' + j.exit_code + (j.truncated ? ', truncated' : '') + ']';
            return { content: s, is_error: j.exit_code !== 0 };
        }
        if (name === 'read_file') {
            return { content: j.content || '', is_error: false };
        }
        if (name === 'write_file') {
            return { content: 'Wrote ' + (j.bytes_written != null ? j.bytes_written + ' bytes to ' : '') + (j.path || ''), is_error: false };
        }
        return { content: JSON.stringify(j), is_error: false };
    }

    function refreshChatStatus() {
        fetch(boot.restRoot.replace(/\/+$/, '') + boot.chatStatus, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j) return;
                chatModel = j.preferred_model || j.default_model || 'claude-opus-4-7';
                if (chatModelPill) chatModelPill.textContent = 'model: ' + chatModel;
                if (!j.has_encryption) {
                    if (chatSetupEl) chatSetupEl.hidden = false;
                    if (chatConvEl)  chatConvEl.hidden  = true;
                    chatKeyStat.className = 'ws-chat-status is-err';
                    chatKeyStat.textContent = 'Encryption key (GS_GCLOUD_CREDS_KEY) not set on the hub — admin needs to configure it before API keys can be stored.';
                    return;
                }
                if (j.connected) {
                    if (chatSetupEl) chatSetupEl.hidden = true;
                    if (chatConvEl)  chatConvEl.hidden  = false;
                } else {
                    if (chatSetupEl) chatSetupEl.hidden = false;
                    if (chatConvEl)  chatConvEl.hidden  = true;
                    chatKeyStat.className = 'ws-chat-status';
                    chatKeyStat.textContent = '';
                }
            });
    }

    if (chatKeyForm) chatKeyForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var key = (chatKeyInput.value || '').trim();
        if (!key.startsWith('sk-ant-')) {
            chatKeyStat.className = 'ws-chat-status is-err';
            chatKeyStat.textContent = 'API key must start with sk-ant-.';
            return;
        }
        chatKeyStat.className = 'ws-chat-status';
        chatKeyStat.textContent = 'Saving…';
        var fd = new FormData();
        fd.append('api_key', key);
        if (chatKeyModel.value) fd.append('model', chatKeyModel.value);
        fetch(boot.restRoot.replace(/\/+$/, '') + boot.chatKey, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' },
            body: fd
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (j && j.connected) {
                chatKeyInput.value = '';
                chatKeyStat.className = 'ws-chat-status is-ok';
                chatKeyStat.textContent = '✓ Saved';
                setTimeout(refreshChatStatus, 400);
            } else {
                chatKeyStat.className = 'ws-chat-status is-err';
                chatKeyStat.textContent = (j && (j.message || j.code)) || 'Save failed.';
            }
        });
    });

    if (chatOffBtn) chatOffBtn.addEventListener('click', function () {
        if (!window.confirm('Disconnect your Anthropic API key from this account?')) return;
        fetch(boot.restRoot.replace(/\/+$/, '') + boot.chatKey, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': boot.nonce, 'Accept': 'application/json' }
        }).then(function () { chatHistory = []; chatRender(); refreshChatStatus(); });
    });

    if (chatResetBtn) chatResetBtn.addEventListener('click', function () {
        chatHistory = [];
        chatRender();
    });

    if (chatStopBtn) chatStopBtn.addEventListener('click', function () {
        if (chatAbort) try { chatAbort.abort(); } catch (e) {}
    });

    if (chatComposer) {
        chatComposer.addEventListener('submit', function (e) { e.preventDefault(); chatSend(); });
        chatInputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); chatSend(); }
        });
    }

    function chatSend(prefillText) {
        var text = (prefillText !== undefined ? prefillText : chatInputEl.value).trim();
        if (!text) return;
        chatInputEl.value = '';
        chatHistory.push({ role: 'user', content: text });
        chatRender();
        chatRunTurn();
    }

    /**
     * Run one Claude turn against chatHistory. If the response contains
     * tool_use blocks, dispatch them, append the user/tool_result
     * message, and recurse — up to a safety cap of 8 hops.
     */
    function chatRunTurn(depth) {
        depth = depth || 0;
        if (depth > 8) {
            chatHistory.push({ role: 'error', content: 'Tool loop hit 8-hop safety cap.' });
            chatRender();
            return;
        }
        var asst = { role: 'assistant', content: [], streaming: true };
        chatHistory.push(asst);
        chatRender();
        chatSendBtn.hidden = true;
        chatStopBtn.hidden = false;
        chatAbort = new AbortController();

        // Build messages for the wire: drop our placeholder assistant
        // (it's empty), and ensure non-string content is forwarded
        // as-is (so prior tool_use blocks survive).
        var wireMessages = chatHistory.filter(function (m) { return m.role !== 'error'; })
                                       .slice(0, -1)
                                       .map(function (m) { return { role: m.role, content: m.content }; });

        // Collector for tool_use blocks streamed in this turn.
        var blocks = [];        // accumulates {index → {type, ...}}
        var stopReason = null;

        fetch(boot.restRoot.replace(/\/+$/, '') + boot.chatStream, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': boot.nonce, 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
            body: JSON.stringify({
                model:    chatModel || undefined,
                messages: wireMessages,
                tools:    CHAT_TOOLS
            }),
            signal: chatAbort.signal
        }).then(function (resp) {
            if (!resp.body) throw new Error('SSE unsupported.');
            var reader = resp.body.getReader();
            var dec = new TextDecoder();
            var buf = '';
            var partialJson = {};   // index → string buffer for input_json_delta

            function pump() {
                return reader.read().then(function (chunk) {
                    if (chunk.done) return;
                    buf += dec.decode(chunk.value, { stream: true });
                    var parts = buf.split('\n\n');
                    buf = parts.pop();
                    for (var i = 0; i < parts.length; i++) {
                        var lines = parts[i].split('\n');
                        var event = '', data = '';
                        for (var k = 0; k < lines.length; k++) {
                            if (lines[k].indexOf('event:') === 0) event = lines[k].slice(6).trim();
                            else if (lines[k].indexOf('data:') === 0) data += lines[k].slice(5).trim();
                        }
                        if (!data) continue;
                        try {
                            var ev = JSON.parse(data);
                            handleStreamEvent(ev, blocks, partialJson, asst);
                            if (ev.type === 'message_delta' && ev.delta && ev.delta.stop_reason) stopReason = ev.delta.stop_reason;
                        } catch (e) {}
                    }
                    return pump();
                });
            }
            return pump();
        }).then(function () {
            asst.streaming = false;
            // Finalize the assistant block list from accumulator (in
            // order, ignore empty text blocks).
            var contentBlocks = blocks.filter(function (b) {
                if (!b) return false;
                if (b.type === 'text') return (b.text || '').length > 0;
                return true;
            });
            asst.content = contentBlocks.length === 1 && contentBlocks[0].type === 'text'
                ? contentBlocks[0].text  // string content for simple cases
                : contentBlocks;
            chatRender();
            chatSendBtn.hidden = false;
            chatStopBtn.hidden = true;
            chatAbort = null;

            // If Claude wants tools, dispatch + recurse.
            var toolUses = contentBlocks.filter(function (b) { return b.type === 'tool_use'; });
            if (stopReason === 'tool_use' && toolUses.length > 0) {
                Promise.all(toolUses.map(function (tu) {
                    return runTool(tu.name, tu.input || {}).then(function (raw) {
                        var fmt = formatToolResult(tu.name, raw);
                        return { type: 'tool_result', tool_use_id: tu.id, content: fmt.content, is_error: fmt.is_error };
                    });
                })).then(function (results) {
                    chatHistory.push({ role: 'user', content: results });
                    chatRender();
                    chatRunTurn(depth + 1);
                });
            }
        }).catch(function (e) {
            asst.streaming = false;
            if (e && e.name !== 'AbortError') {
                chatHistory.push({ role: 'error', content: e.message || 'stream failed' });
            }
            chatRender();
            chatSendBtn.hidden = false;
            chatStopBtn.hidden = true;
            chatAbort = null;
        });
    }

    function handleStreamEvent(ev, blocks, partialJson, asst) {
        if (ev.type === 'content_block_start' && ev.content_block) {
            blocks[ev.index] = Object.assign({}, ev.content_block);
            if (ev.content_block.type === 'tool_use') {
                partialJson[ev.index] = '';
                blocks[ev.index].input = {};
            }
            if (ev.content_block.type === 'text') {
                blocks[ev.index].text = blocks[ev.index].text || '';
            }
        } else if (ev.type === 'content_block_delta' && ev.delta) {
            if (ev.delta.type === 'text_delta') {
                if (!blocks[ev.index]) blocks[ev.index] = { type: 'text', text: '' };
                blocks[ev.index].text = (blocks[ev.index].text || '') + (ev.delta.text || '');
                // Live-preview: dump current accumulator into asst.content so
                // the streaming cursor sees fresh tokens immediately.
                asst.content = blocks.filter(Boolean);
                chatRender();
            } else if (ev.delta.type === 'input_json_delta') {
                partialJson[ev.index] = (partialJson[ev.index] || '') + (ev.delta.partial_json || '');
            }
        } else if (ev.type === 'content_block_stop') {
            // Parse accumulated JSON for tool_use blocks.
            if (blocks[ev.index] && blocks[ev.index].type === 'tool_use') {
                try { blocks[ev.index].input = JSON.parse(partialJson[ev.index] || '{}'); }
                catch (e) { blocks[ev.index].input = { __parse_error: e.message, raw: partialJson[ev.index] }; }
            }
        }
    }

    refreshChatStatus();

    /* ─── Davinci "ask X" shortcut hooks into the chat sender ─── */
    // Patch the existing dispatchCommand to honor "davinci ask …"
    var __origDispatch = (typeof dispatchCommand === 'function') ? dispatchCommand : null;
    window.__wsDavinciAsk = function (rest) {
        setTab('chat');
        chatSend(rest);
    };

    function davStop() {
        var r = davRecog; davRecog = null;
        if (r) try { r.stop(); } catch (e) {}
        setDavStatus('idle', false);
        davToggle.textContent = '🎙 Start Listening';
    }
    davToggle.addEventListener('click', function () { davRecog ? davStop() : davStart(); });
    if (!Recog) setDavStatus('voice unsupported in this browser', false);

})();
