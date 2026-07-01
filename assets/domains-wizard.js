/**
 * Phase 72-02 Connect-a-Domain wizard — vanilla JS state machine.
 *
 * Drives the 4-step UI:
 *   Step 1: Domain entry (apex-only client-side regex; POSTs domains/connect)
 *   Step 2: Import review (auto-runs import-records; renders records table; enables continue)
 *   Step 3: Nameservers reveal (handles 409 import_review_required by jumping back to Step 2)
 *   Step 4: Status polling (15s recursive setTimeout; visibilitychange pause; 24h timeout)
 *
 * NO jQuery. Reuses gs_remote_membership_call via the 6 wp_ajax_gs_membership_domain_*
 * proxy handlers in dashboard-remote-membership.php.
 *
 * @package gend-society
 * @since   v10.0 / Phase 72-02
 */
(function () {
    'use strict';

    // Apex-only domain regex per Phase 72-02 spec. Rejects subdomains, protocols, paths.
    // Accepts: example.com, mydomain.co.uk, xn--punycode.com
    // Rejects: foo.example.com, https://example.com, example.com/path, www.example.com
    var APEX_DOMAIN_RE = /^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.[a-z]{2,63}(\.[a-z]{2,63})?$/i;

    function validateApexDomain(domain) {
        if (!domain || typeof domain !== 'string') return false;
        var d = domain.trim().toLowerCase();
        if (d.length === 0 || d.length > 253) return false;
        if (d.indexOf('/') >= 0 || d.indexOf(':') >= 0 || d.indexOf(' ') >= 0) return false;
        if (d.indexOf('?') >= 0 || d.indexOf('#') >= 0) return false;
        if (d.indexOf('www.') === 0) return false;
        return APEX_DOMAIN_RE.test(d);
    }

    var state = {
        currentStep: 1,
        zoneId: null,
        domain: '',
        nameservers: [],
        importedRecords: [],
        pollTimeoutId: null,
        pollStartTime: null,
        visibilityListenerAttached: false
    };

    var cfg = (typeof window.gsDomainsWizard !== 'undefined') ? window.gsDomainsWizard : {};
    var i18n = (cfg && cfg.i18n) ? cfg.i18n : {};

    function $(id) { return document.getElementById(id); }
    function show(el) { if (el) el.style.display = ''; }
    function hide(el) { if (el) el.style.display = 'none'; }
    function setText(el, txt) { if (el) el.textContent = txt; }
    function setError(elId, msg) {
        var el = $(elId);
        if (el) { el.textContent = msg; show(el); }
    }
    function clearError(elId) {
        var el = $(elId);
        if (el) { el.textContent = ''; hide(el); }
    }

    function ajaxCall(action, body, onSuccess, onError) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', cfg.nonce || '');
        if (body) {
            Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
        }
        fetch(cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (resp) {
                return resp.json().then(function (json) {
                    return { ok: resp.ok, status: resp.status, json: json };
                });
            })
            .then(function (r) {
                if (r.json && r.json.success) {
                    onSuccess(r.json.data || {});
                } else {
                    var errData = (r.json && r.json.data) ? r.json.data : { message: 'Unknown error', code: 'unknown' };
                    onError(errData, r.status);
                }
            })
            .catch(function (err) {
                onError({ message: String(err), code: 'network' }, 0);
            });
    }

    function jumpToStep(n) {
        state.currentStep = n;
        // Update panel visibility.
        var panels = document.querySelectorAll('.gs-domains-wizard__panel');
        for (var i = 0; i < panels.length; i++) {
            panels[i].classList.toggle('is-active', String(i + 1) === String(n));
        }
        // Update step indicator.
        var steps = document.querySelectorAll('.gs-domains-wizard__step');
        for (var j = 0; j < steps.length; j++) {
            steps[j].classList.toggle('is-active', (j + 1) <= n);
        }
    }

    function mapErrorMessage(errData) {
        if (!errData || !errData.code) return errData.message || 'Unknown error';
        switch (errData.code) {
            case 'cf_auth_missing': return i18n.cfAuthMissing || errData.message;
            case 'cf_rate_limit':
                return (i18n.cfRateLimit || 'Cloudflare rate-limited. Retry after {n}s.').replace('{n}', errData.retry_after || '?');
            case 'cf_import_failed': return i18n.cfImportFailed || errData.message;
            case 'cf_network':
            case 'cf_api_error': return i18n.cfNetwork || errData.message;
            case 'not_found': return i18n.notFound || errData.message;
            case 'import_review_required': return i18n.importReviewRequired || errData.message;
            default: return errData.message || 'Unknown error';
        }
    }

    // Step 1: Domain entry submit
    function handleStep1Submit(e) {
        e.preventDefault();
        clearError('gs-domains-wizard-step1-error');
        var input = $('gs-domains-wizard-domain');
        var domain = (input && input.value) ? input.value.trim().toLowerCase() : '';
        if (!validateApexDomain(domain)) {
            setError('gs-domains-wizard-step1-error', i18n.invalidDomain || 'Enter an apex domain (no subdomains).');
            return;
        }
        state.domain = domain;
        ajaxCall('gs_membership_domain_connect', { domain: domain }, function (data) {
            state.zoneId = data.id || data.zone_id || null;
            state.nameservers = (data.name_servers && Array.isArray(data.name_servers)) ? data.name_servers : [];
            if (!state.zoneId) {
                setError('gs-domains-wizard-step1-error', 'Connect succeeded but no zone id returned.');
                return;
            }
            jumpToStep(2);
            runStep2Import();
        }, function (errData) {
            setError('gs-domains-wizard-step1-error', mapErrorMessage(errData));
        });
    }

    // Step 2: Run import + fetch records
    function runStep2Import() {
        clearError('gs-domains-wizard-step2-error');
        hide($('gs-domains-wizard-records-table'));
        show($('gs-domains-wizard-records-loading'));
        var confirmBtn = $('gs-domains-wizard-step2-confirm');
        if (confirmBtn) confirmBtn.disabled = true;

        ajaxCall('gs_membership_domain_import_records', { zone_id: state.zoneId }, function () {
            // After import, fetch the records list.
            ajaxCall('gs_membership_domain_records', { zone_id: state.zoneId }, function (records) {
                hide($('gs-domains-wizard-records-loading'));
                state.importedRecords = Array.isArray(records) ? records : [];
                var tbody = document.querySelector('#gs-domains-wizard-records-table tbody');
                if (tbody) {
                    tbody.innerHTML = '';
                    if (state.importedRecords.length === 0) {
                        var trEmpty = document.createElement('tr');
                        trEmpty.innerHTML = '<td colspan="5" style="font-style:italic; color:var(--gs-muted);">No records found. You can add records manually after delegation.</td>';
                        tbody.appendChild(trEmpty);
                    } else {
                        state.importedRecords.forEach(function (rec) {
                            var tr = document.createElement('tr');
                            tr.innerHTML =
                                '<td>' + escapeHtml(rec.type || '') + '</td>' +
                                '<td>' + escapeHtml(rec.name || '') + '</td>' +
                                '<td><code>' + escapeHtml(rec.content || '') + '</code></td>' +
                                '<td>' + (rec.ttl === 1 ? 'Auto' : escapeHtml(String(rec.ttl))) + '</td>' +
                                '<td>' + (rec.proxied ? 'Yes' : 'No') + '</td>';
                            tbody.appendChild(tr);
                        });
                    }
                }
                show($('gs-domains-wizard-records-table'));
                if (confirmBtn) confirmBtn.disabled = false;
            }, function (errData) {
                hide($('gs-domains-wizard-records-loading'));
                setError('gs-domains-wizard-step2-error', mapErrorMessage(errData));
            });
        }, function (errData) {
            hide($('gs-domains-wizard-records-loading'));
            // cf_import_failed is non-fatal — allow continue with manual-add fallback.
            if (errData.code === 'cf_import_failed') {
                setError('gs-domains-wizard-step2-error', mapErrorMessage(errData));
                if (confirmBtn) confirmBtn.disabled = false;
            } else {
                setError('gs-domains-wizard-step2-error', mapErrorMessage(errData));
            }
        });
    }

    // Step 2 confirm → Step 3
    function handleStep2Confirm() {
        jumpToStep(3);
        runStep3GetNameservers();
    }

    // Step 3: Fetch nameservers (server enforces import_review_required guard)
    function runStep3GetNameservers() {
        clearError('gs-domains-wizard-step3-error');
        ajaxCall('gs_membership_domain_get_nameservers', { zone_id: state.zoneId }, function (data) {
            var ns = (data && data.name_servers && Array.isArray(data.name_servers)) ? data.name_servers : [];
            state.nameservers = ns;
            var list = $('gs-domains-wizard-ns-list');
            if (list) {
                list.innerHTML = '';
                ns.forEach(function (n) {
                    var li = document.createElement('li');
                    li.innerHTML = '<code class="gs-domains-wizard__ns-code">' + escapeHtml(n) + '</code>';
                    list.appendChild(li);
                });
            }
        }, function (errData) {
            if (errData.code === 'import_review_required') {
                // CRITICAL: UI-layer reinforcement of data-layer guard — jump back to Step 2.
                setError('gs-domains-wizard-step2-error', mapErrorMessage(errData));
                jumpToStep(2);
                runStep2Import();
                return;
            }
            setError('gs-domains-wizard-step3-error', mapErrorMessage(errData));
        });
    }

    // Step 3: Copy nameservers to clipboard
    function handleCopyNameservers() {
        var text = state.nameservers.join('\n');
        var btn = $('gs-domains-wizard-copy-btn');
        function showCopied() {
            if (btn) btn.textContent = i18n.copied || 'Copied!';
            setTimeout(function () { if (btn) btn.textContent = i18n.copyAll || 'Copy both'; }, 1800);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(showCopied, function () { fallbackCopy(text, showCopied); });
        } else {
            fallbackCopy(text, showCopied);
        }
    }
    function fallbackCopy(text, onDone) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.focus(); ta.select();
        try { document.execCommand('copy'); onDone(); } catch (e) { /* swallow */ }
        document.body.removeChild(ta);
    }

    // Step 3 continue → Step 4
    function handleStep3Continue() {
        jumpToStep(4);
        state.pollStartTime = Date.now();
        startPolling();
    }

    // Step 4: Recursive setTimeout polling (per CONTEXT decision — not a periodic timer). visibilitychange pause + 24h timeout.
    function startPolling() {
        if (!state.visibilityListenerAttached) {
            document.addEventListener('visibilitychange', function () {
                // Suspend on hide; restart on show (re-arm timer).
                if (document.visibilityState === 'visible' && state.currentStep === 4 && state.pollTimeoutId === null) {
                    scheduleNextPoll();
                }
            });
            state.visibilityListenerAttached = true;
        }
        runStep4PollStatus();
    }

    function scheduleNextPoll() {
        if (document.visibilityState === 'hidden') {
            state.pollTimeoutId = null;
            return; // Paused — visibilitychange listener resumes.
        }
        var elapsed = Date.now() - (state.pollStartTime || Date.now());
        if (elapsed > (cfg.pollTimeoutMs || (24 * 60 * 60 * 1000))) {
            setText($('gs-domains-wizard-status-text'), i18n.pollTimeout || 'Still waiting. See troubleshooting.');
            return;
        }
        state.pollTimeoutId = setTimeout(runStep4PollStatus, cfg.pollIntervalMs || 15000);
    }

    function runStep4PollStatus() {
        state.pollTimeoutId = null;
        ajaxCall('gs_membership_domain_get_status', { zone_id: state.zoneId }, function (data) {
            var status = (data && data.status) ? String(data.status) : 'pending';
            var lastPolled = (data && data.last_polled_at) ? new Date(data.last_polled_at * 1000) : new Date();
            setText($('gs-domains-wizard-status-last'), 'Last checked: ' + lastPolled.toLocaleTimeString());
            if (status === 'active') {
                hide($('gs-domains-wizard-status-poll'));
                show($('gs-domains-wizard-success'));
                refreshConnectedList();
                return; // Done — no more polling.
            }
            scheduleNextPoll();
        }, function (errData) {
            // Soft error — keep polling.
            setText($('gs-domains-wizard-status-last'), 'Poll error: ' + mapErrorMessage(errData) + ' — retrying...');
            scheduleNextPoll();
        });
    }

    // Connected-domains list refresh (DCON-05)
    function refreshConnectedList() {
        ajaxCall('gs_membership_domain_list', {}, function (rows) {
            var tbody = $('gs-domains-wizard-connected-tbody');
            if (!tbody) return;
            if (!Array.isArray(rows) || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="gs-hosting__section-sub" style="font-style:italic;">No domains connected yet.</td></tr>';
                return;
            }
            tbody.innerHTML = '';
            // Surface stale-pending-zone banner if any pending zone is older than 7 days.
            var staleEntries = [];
            var now = Math.floor(Date.now() / 1000);
            var SEVEN_DAYS = 7 * 24 * 60 * 60;
            rows.forEach(function (row) {
                var createdAt = row.created_at ? parseInt(row.created_at, 10) : 0;
                if (row.zone_status === 'pending' && createdAt > 0 && (now - createdAt) > SEVEN_DAYS) {
                    staleEntries.push(row.host || '');
                }
                var tr = document.createElement('tr');
                // Phase 73-02: Edit-records button opens the records-editor modal for this
                // zone. records-editor.js delegates its own click handler on [data-edit-records]
                // reading data-zone-id + data-zone-host. Continue button UNCHANGED (Phase 72).
                // Phase 74-02: compact SSL badge (dot + tooltip) rendered alongside the buttons.
                // Populated by refreshSslBadgesInRow (per-badge AJAX fan-out) + polled every 60s
                // via scheduleWizardSslPoll with visibilitychange pause.
                var sslBadgeHtml =
                    '<span class="gs-domain-ssl-badge" ' +
                    'data-ssl-badge="1" ' +
                    'data-zone-host="' + escapeHtml(row.host || '') + '" ' +
                    'data-state="unknown" ' +
                    'title="Checking SSL...">' +
                    '<span class="gs-domain-ssl-badge-dot" aria-hidden="true"></span>' +
                    '</span>';
                tr.innerHTML =
                    '<td>' + escapeHtml(row.host || '') + '</td>' +
                    '<td>' + escapeHtml(row.import_status || 'pending') + '</td>' +
                    '<td>' + escapeHtml(row.zone_status || row.status || 'pending') + '</td>' +
                    '<td><button type="button" class="gs-domains-wizard__btn" data-resume-zone="' + escapeHtml(String(row.id || '')) + '" data-resume-import="' + escapeHtml(row.import_status || 'pending') + '" data-resume-zonestatus="' + escapeHtml(row.zone_status || 'pending') + '">Continue</button>' +
                    ' <button type="button" class="gs-domains-wizard__btn" data-edit-records data-zone-id="' + escapeHtml(String(row.id || '')) + '" data-zone-host="' + escapeHtml(row.host || '') + '">Edit records</button>' +
                    ' ' + sslBadgeHtml + '</td>';
                tbody.appendChild(tr);
            });
            // Render stale banner if needed.
            var staleBanner = $('gs-domains-wizard-stale');
            if (staleBanner) {
                if (staleEntries.length > 0) {
                    var listEl = staleBanner.querySelector('.gs-domains-wizard__stale-list');
                    if (listEl) listEl.textContent = ' ' + staleEntries.join(', ');
                    staleBanner.style.display = '';
                } else {
                    staleBanner.style.display = 'none';
                }
            }
            // Phase 74-02: populate compact SSL badges via per-badge AJAX fan-out.
            refreshSslBadgesInRow();
            scheduleWizardSslPoll();
        }, function () { /* swallow — non-critical */ });
    }

    // Phase 74-02: per-row compact SSL badge polling.
    // Same 60s cadence as records-editor modal; recursive setTimeout with
    // visibilitychange pause (interval invariant preserved — timeouts only).
    // 6 badge states mirror the 74-01 consolidated overall classifier —
    // MAKE-OR-BREAK: active_ipv4_only NEVER shown as green (see domains-wizard.css).
    function refreshSslBadgesInRow() {
        var badges = document.querySelectorAll('[data-ssl-badge][data-zone-host]');
        for (var i = 0; i < badges.length; i++) {
            (function (el) {
                var host = el.getAttribute('data-zone-host');
                if (!host) { return; }
                ajaxCall('gs_membership_domain_ssl_status', { host: host }, function (data) {
                    var d = (data && typeof data === 'object') ? data : {};
                    var overall = d.overall ? String(d.overall) : 'unknown';
                    el.setAttribute('data-state', overall);
                    el.setAttribute('title', 'SSL: ' + overall.replace(/_/g, ' '));
                }, function () {
                    // Read-path swallow — badge stays 'unknown'.
                });
            })(badges[i]);
        }
    }

    var wizardSslPollTimer = null;
    function scheduleWizardSslPoll() {
        if (wizardSslPollTimer) { clearTimeout(wizardSslPollTimer); wizardSslPollTimer = null; }
        if (document.hidden) { return; }
        wizardSslPollTimer = setTimeout(function () {
            refreshSslBadgesInRow();
            scheduleWizardSslPoll();
        }, 60000);
    }
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (wizardSslPollTimer) { clearTimeout(wizardSslPollTimer); wizardSslPollTimer = null; }
        } else {
            // Refresh once on resume (matches records-editor.js pattern).
            refreshSslBadgesInRow();
            scheduleWizardSslPoll();
        }
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Cleanup on unload.
    window.addEventListener('beforeunload', function () {
        if (state.pollTimeoutId !== null) {
            clearTimeout(state.pollTimeoutId);
            state.pollTimeoutId = null;
        }
    });

    // Wire up event listeners on DOM ready
    document.addEventListener('DOMContentLoaded', function () {
        var form = $('gs-domains-wizard-step1-form');
        if (form) form.addEventListener('submit', handleStep1Submit);
        var btn2 = $('gs-domains-wizard-step2-confirm');
        if (btn2) btn2.addEventListener('click', handleStep2Confirm);
        var copy = $('gs-domains-wizard-copy-btn');
        if (copy) copy.addEventListener('click', handleCopyNameservers);
        var btn3 = $('gs-domains-wizard-step3-continue');
        if (btn3) btn3.addEventListener('click', handleStep3Continue);

        // Resume buttons in connected-domains table
        document.addEventListener('click', function (ev) {
            var t = ev.target;
            if (t && t.dataset && t.dataset.resumeZone) {
                state.zoneId = parseInt(t.dataset.resumeZone, 10);
                if (t.dataset.resumeImport === 'pending') {
                    jumpToStep(2); runStep2Import();
                } else if (t.dataset.resumeZonestatus !== 'active') {
                    jumpToStep(3); runStep3GetNameservers();
                } else {
                    jumpToStep(4); state.pollStartTime = Date.now(); startPolling();
                }
            }
        });

        refreshConnectedList();
    });
})();
