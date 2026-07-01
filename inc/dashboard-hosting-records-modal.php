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
function gs_render_hosting_records_modal( $payload = array() ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Enqueue records-editor assets. GS_VERSION cascade + filemtime cache-buster
    // per project_social_membership_assets convention (single-source bump in
    // gend-society.php cascades to every wp_enqueue site-wide).
    $js_ver  = defined( 'GS_VERSION' ) ? GS_VERSION : '0';
    $css_ver = $js_ver;
    if ( defined( 'GS_DIR' ) && file_exists( GS_DIR . 'assets/records-editor.js' ) ) {
        $js_ver = $js_ver . '.' . filemtime( GS_DIR . 'assets/records-editor.js' );
    }
    if ( defined( 'GS_DIR' ) && file_exists( GS_DIR . 'assets/records-editor.css' ) ) {
        $css_ver = $css_ver . '.' . filemtime( GS_DIR . 'assets/records-editor.css' );
    }
    wp_enqueue_style( 'gs-records-editor', GS_URL . 'assets/records-editor.css', array(), $css_ver );
    wp_enqueue_script( 'gs-records-editor', GS_URL . 'assets/records-editor.js', array(), $js_ver, true );
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
                'lastChangeAgo'      => __( 'Last change: %s ago', 'gend-society' ),
                'cfAuthMissing'      => __( 'Cloudflare is not configured on the hub. Contact your operator.', 'gend-society' ),
                'cfRateLimit'        => __( 'Cloudflare rate-limited. Retry in {n} seconds.', 'gend-society' ),
                'cfNetwork'          => __( 'Cloudflare unreachable. Retry shortly.', 'gend-society' ),
                'notFound'           => __( 'Record or zone not found.', 'gend-society' ),
                'serverError'        => __( 'Server error. Please retry.', 'gend-society' ),
                'undoUnsupported'    => __( 'This change cannot be undone.', 'gend-society' ),
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
                <button type="button" class="gs-records-editor__close" data-close-modal aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
            </header>
            <div class="gs-records-editor__toolbar">
                <button type="button" class="gs-records-editor__btn is-primary" id="gs-records-editor-add"><?php esc_html_e( 'Add record', 'gend-society' ); ?></button>
                <span class="gs-records-editor__status" id="gs-records-editor-status"></span>
            </div>
            <div class="gs-records-editor__body" id="gs-records-editor-body">
                <div class="gs-records-editor__loading"><?php esc_html_e( 'Loading records…', 'gend-society' ); ?></div>
            </div>
            <div class="gs-records-editor__error" id="gs-records-editor-error" hidden></div>
        </div>
    </div>
    <?php
}
