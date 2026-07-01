/**
 * Records-Editor — Phase 73-02 of v10.0.
 *
 * Vanilla JS state machine for the DNS records-editor modal. Consumes 4 AJAX
 * proxies (record_create / _update / _delete / _undo) which pipe to the 4 new
 * REST routes from Phase 73-01 under gdc-app-manager/v1.
 *
 * Locked CONTEXT decisions:
 *  - Per-type fields are a flat object matching the CF API verbatim
 *  - Single "Undo last change" button in the modal header (reads last_change
 *    from records-list response include_last_change=1 enrichment)
 *  - Proxy toggle is DISABLED with tooltip on MX/TXT/SRV (not hidden)
 *  - Single error-message string per field
 *  - Apex '@' is rewritten to zone root on server-side BEFORE audit write
 *
 * Vanilla implementation only (no legacy DOM libs). No new CSS keyframes. No third-party deps.
 *
 * @package gend-society
 * @since   v10.0 / Phase 73-02
 */
(function () {
    'use strict';

    var cfg = (typeof window.gsRecordsEditor !== 'undefined') ? window.gsRecordsEditor : {};
    var i18n = (cfg && cfg.i18n) ? cfg.i18n : {};

    var state = {
        zoneId: null,
        zoneHost: '',
        records: [],
        lastChange: null,
        view: 'list',
        editingRecordId: null,
        pendingDeleteRecord: null,
        pendingDeleteWarning: null,
    };

    // ── Type metadata ──────────────────────────────────────────────
    var PROXYABLE_TYPES = ['A', 'AAAA', 'CNAME'];
    var ALL_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV'];

    function isProxyable(type) {
        return PROXYABLE_TYPES.indexOf(String(type || '').toUpperCase()) !== -1;
    }

    // ── Validation (client-side mirror of server _validate_record_payload) ─
    // Server-side is authoritative; drift-prevention gate is UAT 73-03 Step 7.
    function validatePayload(payload) {
        var t = String(payload.type || '').toUpperCase();
        if (ALL_TYPES.indexOf(t) === -1) {
            return { ok: false, field: 'type', message: i18n.errTypeInvalid || 'Unsupported record type.' };
        }
        if (!payload.name) {
            return { ok: false, field: 'name', message: i18n.errNameRequired || 'Name is required.' };
        }
        if (payload.name !== '@' && !/^[a-zA-Z0-9._-]+$/.test(payload.name)) {
            return { ok: false, field: 'name', message: i18n.errNameInvalid || 'Name contains invalid characters.' };
        }
        if (String(payload.name).length > 253) {
            return { ok: false, field: 'name', message: i18n.errNameInvalid || 'Name exceeds 253 characters.' };
        }
        var ttl = parseInt(payload.ttl, 10);
        if (isNaN(ttl)) ttl = 1;
        if (ttl !== 1 && (ttl < 60 || ttl > 86400)) {
            return { ok: false, field: 'ttl', message: i18n.errTtlRange || 'TTL must be 1 (Auto) or 60–86400 seconds.' };
        }
        if (t === 'A') {
            var v4 = String(payload.content || '');
            if (!/^(\d{1,3}\.){3}\d{1,3}$/.test(v4)) {
                return { ok: false, field: 'content', message: i18n.errInvalidIpv4 || 'Must be a valid IPv4 address.' };
            }
            var octets = v4.split('.');
            for (var i = 0; i < octets.length; i++) {
                var n = parseInt(octets[i], 10);
                if (isNaN(n) || n < 0 || n > 255) {
                    return { ok: false, field: 'content', message: i18n.errInvalidIpv4 };
                }
            }
        } else if (t === 'AAAA') {
            if (!/^[0-9a-fA-F:]+$/.test(String(payload.content || '')) || (payload.content || '').indexOf(':') === -1) {
                return { ok: false, field: 'content', message: i18n.errInvalidIpv6 || 'Must be a valid IPv6 address.' };
            }
        } else if (t === 'CNAME') {
            if (!/^[a-zA-Z0-9.-]+$/.test(String(payload.content || '')) || String(payload.content || '').length > 253) {
                return { ok: false, field: 'content', message: i18n.errInvalidHostname };
            }
        } else if (t === 'MX') {
            if (payload.proxied) {
                return { ok: false, field: 'proxied', message: i18n.proxyDisabledMx };
            }
            var pri = parseInt(payload.priority, 10);
            if (isNaN(pri) || pri < 0 || pri > 65535) {
                return { ok: false, field: 'priority', message: i18n.errMxPriority };
            }
            if (!/^[a-zA-Z0-9.-]+$/.test(String(payload.content || ''))) {
                return { ok: false, field: 'content', message: i18n.errInvalidHostname };
            }
        } else if (t === 'TXT') {
            if (payload.proxied) {
                return { ok: false, field: 'proxied', message: i18n.proxyDisabledTxt };
            }
            if (String(payload.content || '').length > 255) {
                return { ok: false, field: 'content', message: i18n.errTxtTooLong };
            }
        } else if (t === 'SRV') {
            if (payload.proxied) {
                return { ok: false, field: 'proxied', message: i18n.proxyDisabledSrv };
            }
            var data = payload.data || {};
            var srvFields = ['priority', 'weight', 'port'];
            for (var fi = 0; fi < srvFields.length; fi++) {
                var vv = parseInt(data[srvFields[fi]], 10);
                if (isNaN(vv) || vv < 0 || vv > 65535) {
                    return { ok: false, field: srvFields[fi], message: i18n.errSrvRange };
                }
            }
            if (!/^[a-zA-Z0-9.-]+$/.test(String(data.target || ''))) {
                return { ok: false, field: 'target', message: i18n.errInvalidHostname };
            }
        }
        return { ok: true };
    }

    // ── AJAX layer ─────────────────────────────────────────────────
    function ajaxCall(action, body, onSuccess, onError) {
        var form = new FormData();
        form.append('action', action);
        form.append('nonce', cfg.nonce || '');
        Object.keys(body || {}).forEach(function (k) {
            var v = body[k];
            if (v === null || typeof v === 'undefined') { return; }
            if (typeof v === 'boolean') {
                form.append(k, v ? '1' : '0');
                return;
            }
            if (typeof v === 'object' && !Array.isArray(v)) {
                // Nested object (e.g. SRV data) — flatten as data[priority] etc.
                Object.keys(v).forEach(function (sk) {
                    if (v[sk] !== null && typeof v[sk] !== 'undefined') {
                        form.append(k + '[' + sk + ']', v[sk]);
                    }
                });
            } else {
                form.append(k, v);
            }
        });
        fetch(cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: form })
            .then(function (resp) {
                return resp.json().then(function (json) {
                    return { ok: resp.ok, status: resp.status, json: json };
                });
            })
            .then(function (r) {
                if (r.json && r.json.success) {
                    onSuccess(r.json.data || {}, r.status);
                } else {
                    var err = (r.json && r.json.data) ? r.json.data : { code: 'unknown', message: i18n.serverError || 'Server error.' };
                    onError(err, r.status);
                }
            })
            .catch(function () { onError({ code: 'network', message: i18n.cfNetwork || 'Network error.' }, 0); });
    }

    // ── DOM helpers ────────────────────────────────────────────────
    function $(sel, root) { return (root || document).querySelector(sel); }
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function mapErrorMessage(err) {
        if (!err) return i18n.serverError || 'Server error.';
        if (err.code === 'cf_auth_missing') return i18n.cfAuthMissing || err.message;
        if (err.code === 'cf_rate_limit') {
            var m = String(err.message || '').match(/(\d+)\s*second/);
            return String(i18n.cfRateLimit || 'Cloudflare rate-limited. Retry in {n} seconds.').replace('{n}', m ? m[1] : (err.retry_after || '60'));
        }
        if (err.code === 'cf_network' || err.code === 'cf_api_error') return i18n.cfNetwork || err.message;
        if (err.code === 'not_found') return i18n.notFound || err.message;
        if (err.code === 'proxied_not_supported') return err.message || i18n.proxyDisabledMx;
        if (err.code === 'txt_too_long') return i18n.errTxtTooLong || err.message;
        if (err.code === 'validation_failed') return err.message || 'Validation failed.';
        if (err.code === 'delete_mx_breaks_email') return i18n.warnDeleteMx || err.message;
        if (err.code === 'delete_apex_a_breaks_site') return i18n.warnDeleteApexA || err.message;
        if (err.code === 'undo_unsupported_event') return i18n.undoUnsupported || err.message;
        return err.message || i18n.serverError || 'Server error.';
    }

    // ── Modal lifecycle ────────────────────────────────────────────
    function openModal(zoneId, zoneHost) {
        state.zoneId = parseInt(zoneId, 10) || 0;
        state.zoneHost = String(zoneHost || '');
        state.view = 'list';
        state.editingRecordId = null;
        state.pendingDeleteRecord = null;
        state.pendingDeleteWarning = null;
        var modal = $('#gs-records-editor');
        if (!modal || state.zoneId <= 0) { return; }
        modal.hidden = false;
        var title = $('#gs-records-editor-title');
        if (title) {
            title.textContent = (i18n.modalTitle || 'DNS Records') + (state.zoneHost ? ' — ' + state.zoneHost : '');
        }
        fetchRecords();
    }
    function closeModal() {
        var modal = $('#gs-records-editor');
        if (modal) { modal.hidden = true; }
        state.zoneId = null;
        state.zoneHost = '';
        state.records = [];
        state.lastChange = null;
        state.view = 'list';
        state.editingRecordId = null;
        state.pendingDeleteRecord = null;
        state.pendingDeleteWarning = null;
    }

    // ── Fetch + render list ────────────────────────────────────────
    function fetchRecords() {
        renderLoading();
        // Defensive re-check: if state.zoneId was cleared (modal closed mid-flight), abort.
        if (!state.zoneId) { return; }
        ajaxCall('gs_membership_domain_records',
            { zone_id: state.zoneId, zone_host: state.zoneHost, include_last_change: 1 },
            function (data) {
                // Defense-in-depth against foreign-install data leak: server IS
                // authoritative (IDOR guard at REST layer per Phase 73-01), UI
                // just re-checks state before rendering.
                if (!state.zoneId) { return; }
                if (data && typeof data === 'object' && Array.isArray(data.records)) {
                    state.records = data.records;
                    state.lastChange = data.last_change || null;
                } else if (Array.isArray(data)) {
                    state.records = data;
                    state.lastChange = null;
                } else {
                    state.records = [];
                    state.lastChange = null;
                }
                state.view = 'list';
                renderList();
                renderUndoButton();
            },
            function (err) { renderError(mapErrorMessage(err)); }
        );
    }

    function renderLoading() {
        var body = $('#gs-records-editor-body');
        if (body) {
            body.innerHTML = '<div class="gs-records-editor__loading">' + escapeHtml(i18n.loading || 'Loading…') + '</div>';
        }
    }

    function renderError(msg) {
        var err = $('#gs-records-editor-error');
        if (err) {
            err.textContent = msg;
            err.hidden = false;
            setTimeout(function () { err.hidden = true; }, 6000);
        }
    }

    function renderList() {
        var body = $('#gs-records-editor-body');
        if (!body) return;
        if (!state.records || state.records.length === 0) {
            body.innerHTML = '<div class="gs-records-editor__empty">' + escapeHtml(i18n.noRecords || 'No records yet.') + '</div>';
            return;
        }
        var rows = state.records.map(renderRow).join('');
        body.innerHTML =
            '<table class="gs-records-editor__table">' +
              '<thead><tr>' +
                '<th>' + escapeHtml(i18n.fieldType || 'Type') + '</th>' +
                '<th>' + escapeHtml(i18n.fieldName || 'Name') + '</th>' +
                '<th>' + escapeHtml(i18n.fieldContent || 'Value') + '</th>' +
                '<th>' + escapeHtml(i18n.fieldTtl || 'TTL') + '</th>' +
                '<th>' + escapeHtml(i18n.fieldProxied || 'Proxied') + '</th>' +
                '<th></th>' +
              '</tr></thead>' +
              '<tbody>' + rows + '</tbody>' +
            '</table>';
    }

    function renderRow(rec) {
        var recId = String(rec.id || '');
        var recType = String(rec.type || '').toUpperCase();
        var ttlLabel = (parseInt(rec.ttl, 10) === 1) ? (i18n.ttlAuto || 'Auto') : String(rec.ttl || '');
        var proxyCell;
        if (isProxyable(recType)) {
            proxyCell = '<input type="checkbox" data-toggle-proxy data-record-id="' + escapeHtml(recId) + '"' + (rec.proxied ? ' checked' : '') + '>';
        } else {
            var tip = (recType === 'MX') ? (i18n.proxyDisabledMx || '')
                    : (recType === 'TXT') ? (i18n.proxyDisabledTxt || '')
                    : (i18n.proxyDisabledSrv || '');
            proxyCell = '<input type="checkbox" disabled title="' + escapeHtml(tip) + '">';
        }
        return '<tr data-record-id="' + escapeHtml(recId) + '">' +
            '<td>' + escapeHtml(recType) + '</td>' +
            '<td>' + escapeHtml(rec.name || '') + '</td>' +
            '<td><code>' + escapeHtml(rec.content || '') + '</code></td>' +
            '<td>' + escapeHtml(ttlLabel) + '</td>' +
            '<td>' + proxyCell + '</td>' +
            '<td>' +
              '<button type="button" class="gs-records-editor__btn" data-edit-record="' + escapeHtml(recId) + '">' + escapeHtml(i18n.edit || 'Edit') + '</button>' +
              ' <button type="button" class="gs-records-editor__btn is-danger" data-delete-record="' + escapeHtml(recId) + '">' + escapeHtml(i18n.delete || 'Delete') + '</button>' +
            '</td>' +
          '</tr>';
    }

    // ── Add / Edit form ────────────────────────────────────────────
    function renderAddForm() {
        state.view = 'add';
        state.editingRecordId = null;
        var body = $('#gs-records-editor-body');
        if (!body) return;
        body.innerHTML = renderRecordForm(null);
        wireFormEvents(null);
    }
    function renderEditForm(record) {
        state.view = 'edit';
        state.editingRecordId = String(record.id || '');
        var body = $('#gs-records-editor-body');
        if (!body) return;
        body.innerHTML = renderRecordForm(record);
        wireFormEvents(record);
    }

    function renderRecordForm(record) {
        var isEdit = !!record;
        var r = record || { type: 'A', name: '', content: '', ttl: 1, proxied: false };
        var currentType = String(r.type || 'A').toUpperCase();
        var priority = (r.priority !== undefined && r.priority !== null) ? r.priority : (r.data && r.data.priority) || 10;
        var srvData = r.data || { priority: 10, weight: 1, port: 443, target: '' };

        var typeOptions = ALL_TYPES.map(function (t) {
            var sel = (t === currentType) ? ' selected' : '';
            return '<option value="' + t + '"' + sel + '>' + t + '</option>';
        }).join('');

        var canProxy = isProxyable(currentType);
        var proxyDisabledAttr = canProxy ? '' : ' disabled';
        var proxyTip = canProxy ? '' :
            ((currentType === 'MX') ? (i18n.proxyDisabledMx || '')
              : (currentType === 'TXT') ? (i18n.proxyDisabledTxt || '')
              : (i18n.proxyDisabledSrv || ''));

        return '<form class="gs-records-editor__form" data-record-form>' +
            '<div class="gs-records-editor__field">' +
              '<label>' + escapeHtml(i18n.fieldType || 'Type') + '</label>' +
              '<select name="type" data-field-type ' + (isEdit ? 'disabled' : '') + '>' + typeOptions + '</select>' +
            '</div>' +
            '<div class="gs-records-editor__field">' +
              '<label>' + escapeHtml(i18n.fieldName || 'Name') + ' <span class="gs-records-editor__hint">' + escapeHtml(i18n.apexHint || 'Use @ for the zone root.') + '</span></label>' +
              '<input type="text" name="name" value="' + escapeHtml(r.name || '') + '" required>' +
            '</div>' +
            '<div class="gs-records-editor__field" data-field-content>' +
              '<label>' + escapeHtml(i18n.fieldContent || 'Value') + '</label>' +
              '<input type="text" name="content" value="' + escapeHtml(r.content || '') + '">' +
            '</div>' +
            '<div class="gs-records-editor__field" data-field-priority ' + (currentType === 'MX' ? '' : 'hidden') + '>' +
              '<label>' + escapeHtml(i18n.fieldPriority || 'Priority') + '</label>' +
              '<input type="number" name="priority" min="0" max="65535" value="' + escapeHtml(String(priority)) + '">' +
            '</div>' +
            '<div class="gs-records-editor__field" data-field-srv ' + (currentType === 'SRV' ? '' : 'hidden') + '>' +
              '<label>' + escapeHtml(i18n.fieldPriority || 'Priority') + '</label>' +
              '<input type="number" name="data[priority]" min="0" max="65535" value="' + escapeHtml(String(srvData.priority || 10)) + '">' +
              '<label>' + escapeHtml(i18n.fieldWeight || 'Weight') + '</label>' +
              '<input type="number" name="data[weight]" min="0" max="65535" value="' + escapeHtml(String(srvData.weight || 1)) + '">' +
              '<label>' + escapeHtml(i18n.fieldPort || 'Port') + '</label>' +
              '<input type="number" name="data[port]" min="0" max="65535" value="' + escapeHtml(String(srvData.port || 443)) + '">' +
              '<label>' + escapeHtml(i18n.fieldTarget || 'Target') + '</label>' +
              '<input type="text" name="data[target]" value="' + escapeHtml(srvData.target || '') + '">' +
            '</div>' +
            '<div class="gs-records-editor__field">' +
              '<label>' + escapeHtml(i18n.fieldTtl || 'TTL') + '</label>' +
              '<input type="number" name="ttl" min="1" max="86400" value="' + escapeHtml(String(r.ttl || 1)) + '"> ' +
              '<span class="gs-records-editor__hint">1 = ' + escapeHtml(i18n.ttlAuto || 'Auto') + '</span>' +
            '</div>' +
            '<div class="gs-records-editor__field">' +
              '<label><input type="checkbox" name="proxied"' + (r.proxied ? ' checked' : '') + proxyDisabledAttr +
                (proxyTip ? ' title="' + escapeHtml(proxyTip) + '"' : '') + '> ' +
                escapeHtml(i18n.fieldProxied || 'Proxied') +
              '</label>' +
            '</div>' +
            '<div class="gs-records-editor__form-error" data-form-error hidden></div>' +
            '<div class="gs-records-editor__form-actions">' +
              '<button type="submit" class="gs-records-editor__btn is-primary">' + escapeHtml(i18n.save || 'Save') + '</button>' +
              ' <button type="button" class="gs-records-editor__btn" data-form-cancel>' + escapeHtml(i18n.cancel || 'Cancel') + '</button>' +
            '</div>' +
          '</form>';
    }

    function wireFormEvents(record) {
        var form = $('[data-record-form]');
        if (!form) return;

        // Type-change → show/hide dependent field groups + update proxy tooltip.
        var typeSelect = form.querySelector('[data-field-type]');
        if (typeSelect) {
            typeSelect.addEventListener('change', function () {
                var t = String(typeSelect.value || '').toUpperCase();
                var priField = form.querySelector('[data-field-priority]');
                var srvField = form.querySelector('[data-field-srv]');
                if (priField) { priField.hidden = (t !== 'MX'); }
                if (srvField) { srvField.hidden = (t !== 'SRV'); }
                var proxyBox = form.querySelector('input[name="proxied"]');
                if (proxyBox) {
                    var canP = isProxyable(t);
                    proxyBox.disabled = !canP;
                    if (!canP) {
                        proxyBox.checked = false;
                        proxyBox.title = (t === 'MX') ? (i18n.proxyDisabledMx || '')
                                       : (t === 'TXT') ? (i18n.proxyDisabledTxt || '')
                                       : (i18n.proxyDisabledSrv || '');
                    } else {
                        proxyBox.title = '';
                    }
                }
            });
        }

        // Cancel → back to list.
        var cancelBtn = form.querySelector('[data-form-cancel]');
        if (cancelBtn) { cancelBtn.addEventListener('click', function () { fetchRecords(); }); }

        // Submit → validate + AJAX.
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            handleFormSubmit(form, record);
        });
    }

    function readFormPayload(form) {
        var payload = {
            type: form.querySelector('[name="type"]').value,
            name: form.querySelector('[name="name"]').value.trim(),
            content: form.querySelector('[name="content"]').value.trim(),
            ttl: parseInt(form.querySelector('[name="ttl"]').value, 10) || 1,
            proxied: !!(form.querySelector('[name="proxied"]') && form.querySelector('[name="proxied"]').checked && !form.querySelector('[name="proxied"]').disabled),
        };
        var t = String(payload.type || '').toUpperCase();
        if (t === 'MX') {
            var pri = form.querySelector('[name="priority"]');
            payload.priority = pri ? parseInt(pri.value, 10) : 10;
        }
        if (t === 'SRV') {
            payload.data = {
                priority: parseInt(form.querySelector('[name="data[priority]"]').value, 10) || 0,
                weight: parseInt(form.querySelector('[name="data[weight]"]').value, 10) || 0,
                port: parseInt(form.querySelector('[name="data[port]"]').value, 10) || 0,
                target: form.querySelector('[name="data[target]"]').value.trim(),
            };
        }
        return payload;
    }

    function handleFormSubmit(form, record) {
        var payload = readFormPayload(form);
        var formErr = form.querySelector('[data-form-error]');
        var val = validatePayload(payload);
        if (!val.ok) {
            if (formErr) { formErr.textContent = val.message; formErr.hidden = false; }
            return;
        }
        if (formErr) { formErr.hidden = true; formErr.textContent = ''; }

        // POST body — pipe FLAT to the AJAX handler (record_create / _update).
        var body = {
            zone_id: state.zoneId,
            zone_host: state.zoneHost,
            type: payload.type,
            name: payload.name,
            content: payload.content,
            ttl: payload.ttl,
            proxied: payload.proxied ? 1 : 0,
        };
        if (payload.priority !== undefined) { body.priority = payload.priority; }
        if (payload.data) { body.data = payload.data; }

        if (record && record.id) {
            body.record_id = record.id;
            submitUpdate(body, formErr, false);
        } else {
            ajaxCall('gs_membership_domain_record_create', body,
                function () { fetchRecords(); },
                function (err) {
                    if (formErr) { formErr.textContent = mapErrorMessage(err); formErr.hidden = false; }
                }
            );
        }
    }

    function submitUpdate(body, formErr, force) {
        if (force) { body.force = 1; }
        ajaxCall('gs_membership_domain_record_update', body,
            function () { fetchRecords(); },
            function (err, status) {
                // 409 destructive warning — show Proceed anyway button that retries with force=true.
                if (status === 409 && (err.code === 'delete_mx_breaks_email' || err.code === 'delete_apex_a_breaks_site')) {
                    if (formErr) {
                        formErr.innerHTML = '<div class="gs-records-editor__warning">' +
                            escapeHtml(mapErrorMessage(err)) + ' ' +
                            '<button type="button" class="gs-records-editor__btn is-warning" data-proceed-force>' + escapeHtml(i18n.proceedAnyway || 'Proceed anyway') + '</button>' +
                          '</div>';
                        formErr.hidden = false;
                        var pf = formErr.querySelector('[data-proceed-force]');
                        if (pf) { pf.addEventListener('click', function () { submitUpdate(body, formErr, true); }); }
                    }
                    return;
                }
                if (formErr) { formErr.textContent = mapErrorMessage(err); formErr.hidden = false; }
            }
        );
    }

    // ── Delete confirmation ────────────────────────────────────────
    function handleDelete(recordId) {
        var rec = state.records.find(function (r) { return String(r.id) === String(recordId); });
        if (!rec) return;
        state.pendingDeleteRecord = recordId;
        state.pendingDeleteWarning = null;
        // First attempt: no force. On 409 → re-prompt with warning + Proceed Anyway.
        submitDelete(recordId, false, null);
    }

    function submitDelete(recordId, force, warningMsg) {
        var body = {
            zone_id: state.zoneId,
            zone_host: state.zoneHost,
            record_id: recordId,
        };
        if (force) { body.force = 1; }
        ajaxCall('gs_membership_domain_record_delete', body,
            function () {
                state.pendingDeleteRecord = null;
                state.pendingDeleteWarning = null;
                fetchRecords();
            },
            function (err, status) {
                if (status === 409 && (err.code === 'delete_mx_breaks_email' || err.code === 'delete_apex_a_breaks_site')) {
                    state.pendingDeleteWarning = err.code;
                    renderDeleteWarning(err);
                } else {
                    renderError(mapErrorMessage(err));
                }
            }
        );
    }

    function renderDeleteWarning(err) {
        // Modal-within-modal warning banner + Proceed anyway + Cancel.
        var body = $('#gs-records-editor-body');
        if (!body) return;
        var recordId = state.pendingDeleteRecord;
        var msg = mapErrorMessage(err);
        body.innerHTML = '<div class="gs-records-editor__warning">' +
            '<strong>' + escapeHtml(i18n.confirmDelete || 'Delete this record?') + '</strong>' +
            '<p>' + escapeHtml(msg) + '</p>' +
            '<div class="gs-records-editor__form-actions">' +
              '<button type="button" class="gs-records-editor__btn is-danger" data-confirm-force-delete>' +
                escapeHtml(i18n.proceedAnyway || 'Proceed anyway') +
              '</button> ' +
              '<button type="button" class="gs-records-editor__btn" data-cancel-delete>' +
                escapeHtml(i18n.cancel || 'Cancel') +
              '</button>' +
            '</div>' +
          '</div>';
        var pfBtn = body.querySelector('[data-confirm-force-delete]');
        if (pfBtn) {
            pfBtn.addEventListener('click', function () {
                submitDelete(recordId, true, err.code);
            });
        }
        var cancelBtn = body.querySelector('[data-cancel-delete]');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                state.pendingDeleteRecord = null;
                state.pendingDeleteWarning = null;
                fetchRecords();
            });
        }
    }

    // ── Proxy toggle inline ────────────────────────────────────────
    function handleProxyToggle(recordId, proxied) {
        var rec = state.records.find(function (r) { return String(r.id) === String(recordId); });
        if (!rec) return;
        ajaxCall('gs_membership_domain_record_update',
            {
                zone_id: state.zoneId,
                zone_host: state.zoneHost,
                record_id: recordId,
                type: rec.type,
                name: rec.name,
                content: rec.content,
                ttl: rec.ttl,
                proxied: proxied ? 1 : 0,
            },
            function () { fetchRecords(); },
            function (err) { renderError(mapErrorMessage(err)); fetchRecords(); }
        );
    }

    // ── Undo ────────────────────────────────────────────────────────
    function renderUndoButton() {
        var btn = $('#gs-records-editor-undo');
        if (!btn) return;
        if (!state.lastChange || !state.lastChange.audit_id) {
            btn.hidden = true;
            btn.textContent = '';
            return;
        }
        btn.hidden = false;
        var ago = humanTimeAgo(state.lastChange.created_at);
        var lastLabel = String(i18n.lastChangeAgo || 'Last change: %s ago').replace('%s', ago);
        btn.textContent = lastLabel + ' — ' + (i18n.undoLast || 'Undo');
        btn.setAttribute('data-audit-id', String(state.lastChange.audit_id));
    }

    function handleUndo() {
        if (!state.lastChange || !state.lastChange.audit_id) { return; }
        ajaxCall('gs_membership_domain_record_undo',
            { zone_id: state.zoneId, zone_host: state.zoneHost, audit_id: state.lastChange.audit_id },
            function () { fetchRecords(); },
            function (err) {
                // undo_unsupported_event → show toast, do NOT retry.
                renderError(mapErrorMessage(err));
            }
        );
    }

    function humanTimeAgo(dateStr) {
        try {
            if (!dateStr) return '';
            var s = String(dateStr);
            // MySQL DATETIME comes in as 'YYYY-MM-DD HH:MM:SS' in UTC.
            var iso = s.indexOf('T') === -1 ? s.replace(' ', 'T') + 'Z' : s;
            var then = new Date(iso).getTime();
            if (isNaN(then)) return '';
            var seconds = Math.max(0, Math.floor((Date.now() - then) / 1000));
            if (seconds < 60) return seconds + 's';
            if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
            if (seconds < 86400) return Math.floor(seconds / 3600) + 'h';
            return Math.floor(seconds / 86400) + 'd';
        } catch (e) { return ''; }
    }

    // ── Event wiring ───────────────────────────────────────────────
    function init() {
        // Delegated click handlers.
        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || typeof t.getAttribute !== 'function') return;

            // Edit-records button in connected-domains row (Phase 72 domains-wizard.js template).
            if (t.hasAttribute && t.hasAttribute('data-edit-records')) {
                openModal(t.getAttribute('data-zone-id'), t.getAttribute('data-zone-host'));
                return;
            }
            if (t.hasAttribute && t.hasAttribute('data-close-modal')) {
                closeModal();
                return;
            }
            if (t.id === 'gs-records-editor-add') {
                renderAddForm();
                return;
            }
            if (t.id === 'gs-records-editor-undo') {
                handleUndo();
                return;
            }
            if (t.hasAttribute && t.hasAttribute('data-edit-record')) {
                var eid = t.getAttribute('data-edit-record');
                var rec = state.records.find(function (r) { return String(r.id) === String(eid); });
                if (rec) renderEditForm(rec);
                return;
            }
            if (t.hasAttribute && t.hasAttribute('data-delete-record')) {
                handleDelete(t.getAttribute('data-delete-record'));
                return;
            }
        });

        // Delegated change handler for proxy toggle in the list.
        document.addEventListener('change', function (e) {
            var t = e.target;
            if (t && t.hasAttribute && t.hasAttribute('data-toggle-proxy') && !t.disabled) {
                handleProxyToggle(t.getAttribute('data-record-id'), t.checked);
            }
        });

        // Escape key closes modal.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var modal = $('#gs-records-editor');
                if (modal && !modal.hidden) closeModal();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
