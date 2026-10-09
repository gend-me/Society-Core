<?php
/**
 * Hosting > Domains > Records-Editor Modal — Phase 73-02 of v10.0.
 *
 * Renders the DNS records-editor modal that opens from the connected-domains
 * table row Edit-records button in dashboard-hosting-domains.php. Consumes the
 * 4 new AJAX proxies (record_create / _update / _delete / _undo) which pipe to
 * the 4 new REST routes from Phase 73-01 under gdc-app-manager/v1.
 *
 * Locked CONTEXT decisions:
 *  - Open Q1: per-type fields are a flat object {type,name,content,ttl,proxied,priority?,data?}
 *             matching the CF API verbatim (73-01 SUMMARY key-decisions #3)
 *  - Open Q2: SINGLE "Undo last change" button in the modal header — surfaces the
 *             most-recent ACTION_RECORD_CHANGED audit row via the enriched
 *             records-list response (include_last_change=1)
 *  - Open Q3: proxy toggle DISABLED with tooltip on MX/TXT/SRV (not hidden) —
 *             educates the user without hiding capability
 *  - Open Q4: single error-message string per field
 *  - Open Q5: apex '@' rewrites to zone root on the server-side BEFORE audit
 *             write (handled in 73-01 _validate_record_payload)
 *
 * Companion files:
 *  - assets/records-editor.js  — vanilla JS state machine (Task 3)
 *  - assets/records-editor.css — namespaced glass styling, ZERO new @keyframes (Task 3)
 *
 * @package gend-society
 * @since   v10.0 / Phase 73-02
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render the records-editor modal markup + enqueue assets.
 *
 * The modal is rendered hidden and opened via JS click handler on the
 * connected-domains row [data-edit-records] button. All record data is fetched
 * lazily on open — this function only ships the shell + i18n bag.
 *
 * @param array $payload Membership payload (passed through from
 *                       gs_render_hosting_domains_panel — currently unused;
 *                       the modal is opened per-zone via JS reading
 *                       data-zone-id/data-zone-host from the row).
 * @return void
 */
function gend_society_render_hosting_records_modal( $payload = array() ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Enqueue records-editor assets. GS_VERSION cascade + filemtime cache-buster
    // per project_social_membership_assets convention (single-source bump in
    // gend-society.php cascades to every wp_enqueue site-wide).
    $js_ver  = defined( 'GEND_SOCIETY_VERSION' ) ? GEND_SOCIETY_VERSION : '0';
    $css_ver = $js_ver;
    if ( defined( 'GEND_SOCIETY_DIR' ) && file_exists( GEND_SOCIETY_DIR . 'assets/records-editor.js' ) ) {
        $js_ver = $js_ver . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/records-editor.js' );
    }
    if ( defined( 'GEND_SOCIETY_DIR' ) && file_exists( GEND_SOCIETY_DIR . 'assets/records-editor.css' ) ) {
        $css_ver = $css_ver . '.' . filemtime( GEND_SOCIETY_DIR . 'assets/records-editor.css' );
    }
    wp_enqueue_style( 'gs-records-editor', GEND_SOCIETY_URL . 'assets/records-editor.css', array(), $css_ver );
    wp_enqueue_script( 'gs-records-editor', GEND_SOCIETY_URL . 'assets/records-editor.js', array(), $js_ver, true );
    wp_localize_script(
        'gs-records-editor',
        'gsRecordsEditor',
        array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'gs_membership_action' ),
            'i18n'    => array(
                'modalTitle'         => __( 'DNS Records', 'gend-society' ),
                'loading'            => __( 'Loading records…', 'gend-society' ),
                'noRecords'          => __( 'No DNS records yet. Click Add record to create one.', 'gend-society' ),
                'addRecord'          => __( 'Add record', 'gend-society' ),
                'edit'               => __( 'Edit', 'gend-society' ),
                'delete'             => __( 'Delete', 'gend-society' ),
                'save'               => __( 'Save', 'gend-society' ),
                'cancel'             => __( 'Cancel', 'gend-society' ),
                'close'              => __( 'Close', 'gend-society' ),
                'confirmDelete'      => __( 'Delete this record?', 'gend-society' ),
                'proceedAnyway'      => __( 'Proceed anyway', 'gend-society' ),
                'undoLast'           => __( 'Undo', 'gend-society' ),
                'noChangesToUndo'    => __( 'No changes to undo.', 'gend-society' ),
                'undone'             => __( 'Change undone.', 'gend-society' ),
                'fieldType'          => __( 'Type', 'gend-society' ),
                'fieldName'          => __( 'Name', 'gend-society' ),
                'fieldContent'       => __( 'Value', 'gend-society' ),
                'fieldTtl'           => __( 'TTL', 'gend-society' ),
                'fieldProxied'       => __( 'Proxied', 'gend-society' ),
                'fieldPriority'      => __( 'Priority', 'gend-society' ),
                'fieldWeight'        => __( 'Weight', 'gend-society' ),
                'fieldPort'          => __( 'Port', 'gend-society' ),
                'fieldTarget'        => __( 'Target', 'gend-society' ),
                'ttlAuto'            => __( 'Auto', 'gend-society' ),
                'apexHint'           => __( 'Use @ for the zone root.', 'gend-society' ),
                'proxyDisabledMx'    => __( 'MX records cannot be proxied — orange-cloud only applies to A/AAAA/CNAME.', 'gend-society' ),
                'proxyDisabledTxt'   => __( 'TXT records cannot be proxied.', 'gend-society' ),
                'proxyDisabledSrv'   => __( 'SRV records cannot be proxied.', 'gend-society' ),
                'errInvalidIpv4'     => __( 'A record must be a valid IPv4 address.', 'gend-society' ),
                'errInvalidIpv6'     => __( 'AAAA record must be a valid IPv6 address.', 'gend-society' ),
                'errInvalidHostname' => __( 'Must be a valid hostname.', 'gend-society' ),
                'errMxPriority'      => __( 'MX priority must be 0–65535.', 'gend-society' ),
                'errTxtTooLong'      => __( 'TXT content exceeds 255 characters.', 'gend-society' ),
                'errTtlRange'        => __( 'TTL must be 1 (Auto) or 60–86400 seconds.', 'gend-society' ),
                'errSrvRange'        => __( 'SRV priority/weight/port must be 0–65535.', 'gend-society' ),
                'errNameRequired'    => __( 'Name is required.', 'gend-society' ),
                'errNameInvalid'     => __( 'Name contains invalid characters.', 'gend-society' ),
                'errTypeInvalid'     => __( 'Unsupported record type.', 'gend-society' ),
                'warnDeleteMx'       => __( 'Deleting this MX record will break inbound email for this domain.', 'gend-society' ),
                'warnDeleteApexA'    => __( 'Removing the apex A record while this zone is active will take the site offline.', 'gend-society' ),
                /* translators: %s: Human-readable time difference, e.g. 5 mins. */
                'lastChangeAgo'      => __( 'Last change: %s ago', 'gend-society' ),
                'cfAuthMissing'      => __( 'Cloudflare is not configured on the hub. Contact your operator.', 'gend-society' ),
                'cfRateLimit'        => __( 'Cloudflare rate-limited. Retry in {n} seconds.', 'gend-society' ),
                'cfNetwork'          => __( 'Cloudflare unreachable. Retry shortly.', 'gend-society' ),
                'notFound'           => __( 'Record or zone not found.', 'gend-society' ),
                'serverError'        => __( 'Server error. Please retry.', 'gend-society' ),
                'undoUnsupported'    => __( 'This change cannot be undone.', 'gend-society' ),
                // ── Phase 74-02 additions — Point-at-App + SSL badge + advanced options ──
                'pointAtApp'              => __( 'Point at my app', 'gend-society' ),
                'pointAtAppConfirmTitle'  => __( 'Route this domain at your app?', 'gend-society' ),
                'pointAtAppConfirmBody'   => __( 'This will write apex A + wildcard CNAME + force Full (Strict) SSL. Any existing apex A record will be OVERWRITTEN (backed up in audit log — undo available).', 'gend-society' ),
                'pointAtAppShipItAnyway'  => __( 'Ship it anyway', 'gend-society' ),
                'pointAtAppSuccess'       => __( 'Domain pointed at app. SSL provisioning…', 'gend-society' ),
                'originCertInvalidTitle'  => __( 'Origin cert not yet valid', 'gend-society' ),
                'originCertInvalidBody'   => __( 'Full (Strict) SSL was NOT enabled because your origin cert is not valid yet. DNS records were written; SSL will follow when cert-manager finishes issuance. If your cert IS valid, click below to retry the origin probe.', 'gend-society' ),
                'originCertInvalidRetry'  => __( 'Enable Full (Strict) anyway (my cert IS valid — retry origin probe)', 'gend-society' ),
                'flexibleSslWarnTitle'    => __( 'Flexible SSL is unsafe', 'gend-society' ),
                'flexibleSslWarnBody'     => __( 'Flexible SSL sends plaintext to origin (MITM-able). Are you sure you want to proceed?', 'gend-society' ),
                'sslBadgeProvisioning'    => __( 'Provisioning', 'gend-society' ),
                'sslBadgeActive'          => __( 'Active', 'gend-society' ),
                'sslBadgeMismatched'      => __( 'Mismatched', 'gend-society' ),
                'sslBadgeIpv4Only'        => __( 'IPv4 only', 'gend-society' ),
                'sslBadgeStaleAaaa'       => __( 'Stale AAAA', 'gend-society' ),
                'sslBadgeUnknown'         => __( 'Checking…', 'gend-society' ),
                'sslBadgeTooltipEdge'     => __( 'Cloudflare edge: {state}', 'gend-society' ),
                'sslBadgeTooltipOrigin'   => __( 'Origin cert: {state}', 'gend-society' ),
                'sslBadgeTooltipDualStack'=> __( 'IPv4: {ipv4_ok} | IPv6: {ipv6_ok}', 'gend-society' ),
                'advancedOptionsToggle'   => __( 'Advanced options', 'gend-society' ),
                'sslModeLabel'            => __( 'SSL mode (advanced)', 'gend-society' ),
                'sslModePickPrompt'       => __( '— pick a mode —', 'gend-society' ),
                'sslModeInvalid'          => __( 'Invalid SSL mode.', 'gend-society' ),
                'pointAtAppFailed'        => __( 'Point-at-app failed. Please retry.', 'gend-society' ),
                // ── Phase 75-02 additions — Email preset picker + managed-record protection ──
                'applyPreset'              => __( 'Apply email preset', 'gend-society' ),
                'presetPickerTitle'        => __( 'Choose email preset', 'gend-society' ),
                'presetLabelGoogle'        => __( 'Google Workspace', 'gend-society' ),
                'presetLabelGeneric'       => __( 'Generic email host', 'gend-society' ),
                'presetPreviewHeading'     => __( 'Records to be written', 'gend-society' ),
                'presetFieldMxHost'        => __( 'MX host', 'gend-society' ),
                'presetFieldMxHostHint'    => __( 'Required for Generic preset (e.g. mx.example.com)', 'gend-society' ),
                'presetFieldDkimSelector'  => __( 'DKIM selector', 'gend-society' ),
                'presetFieldDkimValue'     => __( 'DKIM value', 'gend-society' ),
                'presetFieldDkimHint'      => __( 'Paste from Google Admin > Apps > Google Workspace > Gmail > Authenticate email. DKIM improves deliverability but is optional.', 'gend-society' ),
                'presetFieldSpfIncludes'   => __( 'Extra SPF includes (one per line)', 'gend-society' ),
                'presetFieldSpfReplace'    => __( 'Replace default SPF instead of appending', 'gend-society' ),
                'presetFieldDmarcPolicy'   => __( 'DMARC policy', 'gend-society' ),
                'presetApplyBtn'           => __( 'Apply preset', 'gend-society' ),
                'presetCancelBtn'          => __( 'Cancel', 'gend-society' ),
                'presetApplyingStatus'     => __( 'Applying preset…', 'gend-society' ),
                'presetSuccessToast'       => __( 'Applied {preset}: {n} records written.', 'gend-society' ),
                'presetValidationFailed'   => __( 'Preset validation failed at record {index} ({field}): {message}', 'gend-society' ),
                'presetRollbackAlertTitle' => __( 'Preset partially applied — rolled back', 'gend-society' ),
                'presetRollbackAlertBody'  => __( 'The preset failed at record {index}. All previously-written records ({rolled_back}) have been deleted so your zone is back to how it was before. Reason: {reason}', 'gend-society' ),
                'presetRollbackAlertClose' => __( 'Close', 'gend-society' ),
                'spfConflictTitle'         => __( 'Existing SPF record found', 'gend-society' ),
                'spfConflictBody'          => __( 'Your zone already has an SPF record: {existing}. Applying this preset would create a SECOND SPF record and break mail routing (RFC 4408). You can replace the existing SPF or cancel.', 'gend-society' ),
                'spfConflictReplace'       => __( 'Replace SPF and apply preset', 'gend-society' ),
                'spfConflictCancel'        => __( 'Cancel', 'gend-society' ),
                'managedLockTooltip'       => __( 'Written by preset — protected. Click Delete to see a warning.', 'gend-society' ),
                'managedLockAriaLabel'     => __( 'Preset-managed record (protected)', 'gend-society' ),
                'deleteManagedMx'          => __( 'Deleting this MX record will stop email delivery for {host}. Are you sure?', 'gend-society' ),
                'deleteManagedDmarc'       => __( 'Deleting this DMARC record will break email report routing and may reduce deliverability. Are you sure?', 'gend-society' ),
                'deleteManagedDkim'        => __( 'Deleting this DKIM record will break email signing. Emails from this domain may be marked as spam. Are you sure?', 'gend-society' ),
                'deleteManagedSpf'         => __( 'Deleting this SPF record will break email sender verification. Emails may be rejected. Are you sure?', 'gend-society' ),
                'deleteManagedGeneric'     => __( 'This record was written by an email preset. Deleting it may break email. Are you sure?', 'gend-society' ),
                'deleteManagedAnyway'      => __( 'Delete anyway', 'gend-society' ),
                'deleteManagedCancel'      => __( 'Cancel', 'gend-society' ),
            ),
        )
    );
    ?>
    <div id="gs-records-editor" class="gs-records-editor" hidden role="dialog" aria-modal="true" aria-labelledby="gs-records-editor-title">
        <div class="gs-records-editor__backdrop" data-close-modal></div>
        <div class="gs-records-editor__panel">
            <header class="gs-records-editor__header">
                <h3 id="gs-records-editor-title" class="gs-records-editor__title"><?php esc_html_e( 'DNS Records', 'gend-society' ); ?></h3>
                <button type="button" class="gs-records-editor__undo" id="gs-records-editor-undo" hidden></button>
                <?php // Phase 74-02: Point-at-App orchestrator button + SSL badge component (both hidden until openModal). ?>
                <button type="button" class="gs-records-editor__point-at-app" id="gs-records-editor-point-at-app" hidden><?php esc_html_e( 'Point at my app', 'gend-society' ); ?></button>
                <?php // Phase 75-02: Apply-preset button (opens preset picker modal-over-modal; hidden until openModal per Phase 74 chrome pattern). ?>
                <button type="button" class="gs-records-editor__btn is-primary" id="gs-records-editor-apply-preset" data-open-preset-picker="1" hidden><?php esc_html_e( 'Apply email preset', 'gend-society' ); ?></button>
                <span id="gs-records-editor-ssl-badge" class="gs-records-editor__ssl-badge" role="status" aria-live="polite" data-state="unknown" hidden>
                    <span class="gs-records-editor__ssl-badge-dot" aria-hidden="true"></span>
                    <span class="gs-records-editor__ssl-badge-label"><?php esc_html_e( 'Checking…', 'gend-society' ); ?></span>
                </span>
                <button type="button" class="gs-records-editor__close" data-close-modal aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
            </header>
            <div class="gs-records-editor__toolbar">
                <button type="button" class="gs-records-editor__btn is-primary" id="gs-records-editor-add"><?php esc_html_e( 'Add record', 'gend-society' ); ?></button>
                <span class="gs-records-editor__status" id="gs-records-editor-status"></span>
            </div>
            <div class="gs-records-editor__body" id="gs-records-editor-body">
                <div class="gs-records-editor__loading"><?php esc_html_e( 'Loading records…', 'gend-society' ); ?></div>
            </div>
            <?php // Phase 74-02: Advanced options expandable — hidden by default (Open Q3: reduces footgun surface). ?>
            <details id="gs-records-editor-advanced" class="gs-records-editor__advanced">
                <summary><?php esc_html_e( 'Advanced options', 'gend-society' ); ?></summary>
                <div class="gs-records-editor__advanced-body">
                    <label for="gs-records-editor-ssl-mode"><?php esc_html_e( 'SSL mode (advanced)', 'gend-society' ); ?></label>
                    <select id="gs-records-editor-ssl-mode" class="gs-records-editor__ssl-mode-select">
                        <option value=""><?php esc_html_e( '— pick a mode —', 'gend-society' ); ?></option>
                        <option value="off">Off</option>
                        <option value="flexible">Flexible</option>
                        <option value="full">Full</option>
                        <option value="strict">Full (Strict)</option>
                        <option value="origin_pull">Origin Pull</option>
                    </select>
                </div>
            </details>
            <?php // Phase 75-02: preset picker modal-over-modal slot (populated by records-editor.js openPresetPicker; cleared by closePresetPicker). ?>
            <div id="gs-records-editor-preset-picker" class="gs-records-editor__preset-picker" hidden></div>
            <div class="gs-records-editor__error" id="gs-records-editor-error" hidden></div>
        </div>
    </div>
    <?php
}
