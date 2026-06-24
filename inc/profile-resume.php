<?php
/**
 * Member Resume — structured resume builder for the Overview › Resume sub-tab.
 *
 * Storage: a single user_meta key `_gdc_resume` holding a sanitized array:
 *   headline, summary,
 *   experience[] { role, company, start, end, notes },
 *   education[]  { school, credential, year },
 *   skills[],
 *   links[]      { label, url }
 *
 * The profile owner edits inline (add/remove rows, Save via admin-ajax). Other
 * members see the same layout read-only. Rendered by gdc_render_resume_panel(),
 * which is called from the Overview orchestrator in member-profile-pages.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Maximum rows we persist per repeatable section (defensive cap). */
if ( ! defined( 'GDC_RESUME_MAX_ROWS' ) ) define( 'GDC_RESUME_MAX_ROWS', 40 );

/**
 * Fetch a user's saved resume, normalized to the expected shape.
 */
function gdc_get_resume( $user_id ) {
    $data = get_user_meta( (int) $user_id, '_gdc_resume', true );
    if ( ! is_array( $data ) ) $data = array();
    return array(
        'headline'   => isset( $data['headline'] ) ? (string) $data['headline'] : '',
        'summary'    => isset( $data['summary'] ) ? (string) $data['summary'] : '',
        'experience' => isset( $data['experience'] ) && is_array( $data['experience'] ) ? array_values( $data['experience'] ) : array(),
        'education'  => isset( $data['education'] ) && is_array( $data['education'] ) ? array_values( $data['education'] ) : array(),
        'skills'     => isset( $data['skills'] ) && is_array( $data['skills'] ) ? array_values( $data['skills'] ) : array(),
        'links'      => isset( $data['links'] ) && is_array( $data['links'] ) ? array_values( $data['links'] ) : array(),
    );
}

/**
 * Sanitize an incoming resume payload (from the AJAX save) into the stored shape.
 */
function gdc_sanitize_resume( $data ) {
    $out = array(
        'headline'   => sanitize_text_field( $data['headline'] ?? '' ),
        'summary'    => sanitize_textarea_field( $data['summary'] ?? '' ),
        'experience' => array(),
        'education'  => array(),
        'skills'     => array(),
        'links'      => array(),
    );

    if ( ! empty( $data['experience'] ) && is_array( $data['experience'] ) ) {
        foreach ( array_slice( $data['experience'], 0, GDC_RESUME_MAX_ROWS ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $role    = sanitize_text_field( $row['role'] ?? '' );
            $company = sanitize_text_field( $row['company'] ?? '' );
            $start   = sanitize_text_field( $row['start'] ?? '' );
            $end     = sanitize_text_field( $row['end'] ?? '' );
            $notes   = sanitize_textarea_field( $row['notes'] ?? '' );
            if ( $role === '' && $company === '' && $notes === '' ) continue;
            $out['experience'][] = compact( 'role', 'company', 'start', 'end', 'notes' );
        }
    }

    if ( ! empty( $data['education'] ) && is_array( $data['education'] ) ) {
        foreach ( array_slice( $data['education'], 0, GDC_RESUME_MAX_ROWS ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $school     = sanitize_text_field( $row['school'] ?? '' );
            $credential = sanitize_text_field( $row['credential'] ?? '' );
            $year       = sanitize_text_field( $row['year'] ?? '' );
            if ( $school === '' && $credential === '' ) continue;
            $out['education'][] = compact( 'school', 'credential', 'year' );
        }
    }

    if ( ! empty( $data['skills'] ) && is_array( $data['skills'] ) ) {
        foreach ( array_slice( $data['skills'], 0, GDC_RESUME_MAX_ROWS ) as $skill ) {
            $skill = sanitize_text_field( is_array( $skill ) ? '' : $skill );
            if ( $skill !== '' ) $out['skills'][] = $skill;
        }
    }

    if ( ! empty( $data['links'] ) && is_array( $data['links'] ) ) {
        foreach ( array_slice( $data['links'], 0, GDC_RESUME_MAX_ROWS ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $label = sanitize_text_field( $row['label'] ?? '' );
            $url   = esc_url_raw( $row['url'] ?? '' );
            if ( $url === '' ) continue;
            $out['links'][] = compact( 'label', 'url' );
        }
    }

    return $out;
}

/**
 * AJAX: save the current user's resume.
 */
add_action( 'wp_ajax_gdc_save_resume', 'gdc_ajax_save_resume' );
function gdc_ajax_save_resume() {
    check_ajax_referer( 'gdc_resume', 'nonce' );
    $uid = get_current_user_id();
    if ( ! $uid ) {
        wp_send_json_error( array( 'message' => 'Not logged in.' ), 403 );
    }
    $raw  = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) ) {
        wp_send_json_error( array( 'message' => 'Malformed resume data.' ), 400 );
    }
    $clean = gdc_sanitize_resume( $data );
    update_user_meta( $uid, '_gdc_resume', $clean );
    wp_send_json_success( array( 'message' => 'Saved.' ) );
}

/**
 * Render the Resume panel. Editable for the owner; read-only for visitors.
 */
function gdc_render_resume_panel( $displayed_user_id, $is_own ) {
    $displayed_user_id = (int) $displayed_user_id;
    $resume = gdc_get_resume( $displayed_user_id );

    gdc_resume_panel_styles();

    if ( $is_own ) {
        gdc_render_resume_editor( $resume );
    } else {
        gdc_render_resume_readonly( $resume, $displayed_user_id );
    }
}

/**
 * Read-only resume view (visitors / other members' profiles).
 */
function gdc_render_resume_readonly( $resume, $user_id ) {
    $is_empty = $resume['headline'] === '' && $resume['summary'] === ''
        && empty( $resume['experience'] ) && empty( $resume['education'] )
        && empty( $resume['skills'] ) && empty( $resume['links'] );

    if ( $is_empty ) {
        echo '<div class="gdc-resume gdc-resume--ro"><p class="gs-portfolio-empty">'
            . esc_html__( 'This member has not published a resume yet.', 'gend-society' )
            . '</p></div>';
        return;
    }
    ?>
    <div class="gdc-resume gdc-resume--ro">
        <?php if ( $resume['headline'] !== '' ) : ?>
            <h2 class="gdc-resume-headline"><?php echo esc_html( $resume['headline'] ); ?></h2>
        <?php endif; ?>
        <?php if ( $resume['summary'] !== '' ) : ?>
            <p class="gdc-resume-summary"><?php echo nl2br( esc_html( $resume['summary'] ) ); ?></p>
        <?php endif; ?>

        <?php if ( ! empty( $resume['experience'] ) ) : ?>
            <section class="gdc-resume-section">
                <h3><?php esc_html_e( 'Experience', 'gend-society' ); ?></h3>
                <?php foreach ( $resume['experience'] as $row ) : ?>
                    <div class="gdc-resume-entry">
                        <div class="gdc-resume-entry-head">
                            <strong><?php echo esc_html( $row['role'] ?? '' ); ?></strong>
                            <?php if ( ! empty( $row['company'] ) ) : ?>
                                <span class="gdc-resume-at"><?php echo esc_html( $row['company'] ); ?></span>
                            <?php endif; ?>
                            <?php
                            $range = trim( ( $row['start'] ?? '' ) . ( ! empty( $row['end'] ) ? ' – ' . $row['end'] : '' ) );
                            if ( $range !== '' ) : ?>
                                <span class="gdc-resume-dates"><?php echo esc_html( $range ); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ( ! empty( $row['notes'] ) ) : ?>
                            <p class="gdc-resume-notes"><?php echo nl2br( esc_html( $row['notes'] ) ); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ( ! empty( $resume['education'] ) ) : ?>
            <section class="gdc-resume-section">
                <h3><?php esc_html_e( 'Education', 'gend-society' ); ?></h3>
                <?php foreach ( $resume['education'] as $row ) : ?>
                    <div class="gdc-resume-entry">
                        <div class="gdc-resume-entry-head">
                            <strong><?php echo esc_html( $row['credential'] ?? '' ); ?></strong>
                            <?php if ( ! empty( $row['school'] ) ) : ?>
                                <span class="gdc-resume-at"><?php echo esc_html( $row['school'] ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $row['year'] ) ) : ?>
                                <span class="gdc-resume-dates"><?php echo esc_html( $row['year'] ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ( ! empty( $resume['skills'] ) ) : ?>
            <section class="gdc-resume-section">
                <h3><?php esc_html_e( 'Skills', 'gend-society' ); ?></h3>
                <div class="gdc-resume-skills">
                    <?php foreach ( $resume['skills'] as $skill ) : ?>
                        <span class="gdc-resume-skill"><?php echo esc_html( $skill ); ?></span>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ( ! empty( $resume['links'] ) ) : ?>
            <section class="gdc-resume-section">
                <h3><?php esc_html_e( 'Links', 'gend-society' ); ?></h3>
                <div class="gdc-resume-links">
                    <?php foreach ( $resume['links'] as $row ) : ?>
                        <a class="gdc-resume-link" href="<?php echo esc_url( $row['url'] ?? '' ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html( $row['label'] !== '' ? $row['label'] : $row['url'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Editable resume form (profile owner).
 */
function gdc_render_resume_editor( $resume ) {
    $nonce    = wp_create_nonce( 'gdc_resume' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    ?>
    <div class="gdc-resume gdc-resume--edit" id="gdc-resume-editor"
         data-ajax="<?php echo esc_url( $ajax_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">

        <div class="gdc-resume-bar">
            <div>
                <h2 class="gdc-resume-title"><?php esc_html_e( 'Your Resume', 'gend-society' ); ?></h2>
                <p class="gdc-resume-sub"><?php esc_html_e( 'Build out your professional history. Visible on your public profile.', 'gend-society' ); ?></p>
            </div>
            <button type="button" class="gdc-resume-save" id="gdc-resume-save"><?php esc_html_e( 'Save Resume', 'gend-society' ); ?></button>
        </div>
        <p class="gdc-resume-status" id="gdc-resume-status" aria-live="polite"></p>

        <div class="gdc-resume-field">
            <label><?php esc_html_e( 'Headline', 'gend-society' ); ?></label>
            <input type="text" id="gdc-resume-headline" maxlength="160"
                   placeholder="<?php esc_attr_e( 'e.g. Senior Solidity Developer', 'gend-society' ); ?>"
                   value="<?php echo esc_attr( $resume['headline'] ); ?>" />
        </div>

        <div class="gdc-resume-field">
            <label><?php esc_html_e( 'Summary', 'gend-society' ); ?></label>
            <textarea id="gdc-resume-summary" rows="4"
                      placeholder="<?php esc_attr_e( 'A short professional summary…', 'gend-society' ); ?>"><?php echo esc_textarea( $resume['summary'] ); ?></textarea>
        </div>

        <!-- Experience -->
        <section class="gdc-resume-builder" data-section="experience">
            <div class="gdc-resume-builder-head">
                <h3><?php esc_html_e( 'Experience', 'gend-society' ); ?></h3>
                <button type="button" class="gdc-resume-add" data-add="experience">+ <?php esc_html_e( 'Add', 'gend-society' ); ?></button>
            </div>
            <div class="gdc-resume-rows" data-rows="experience">
                <?php foreach ( $resume['experience'] as $row ) gdc_resume_row_experience( $row ); ?>
            </div>
        </section>

        <!-- Education -->
        <section class="gdc-resume-builder" data-section="education">
            <div class="gdc-resume-builder-head">
                <h3><?php esc_html_e( 'Education', 'gend-society' ); ?></h3>
                <button type="button" class="gdc-resume-add" data-add="education">+ <?php esc_html_e( 'Add', 'gend-society' ); ?></button>
            </div>
            <div class="gdc-resume-rows" data-rows="education">
                <?php foreach ( $resume['education'] as $row ) gdc_resume_row_education( $row ); ?>
            </div>
        </section>

        <!-- Skills -->
        <section class="gdc-resume-builder" data-section="skills">
            <div class="gdc-resume-builder-head">
                <h3><?php esc_html_e( 'Skills', 'gend-society' ); ?></h3>
                <button type="button" class="gdc-resume-add" data-add="skills">+ <?php esc_html_e( 'Add', 'gend-society' ); ?></button>
            </div>
            <div class="gdc-resume-rows" data-rows="skills">
                <?php foreach ( $resume['skills'] as $skill ) gdc_resume_row_skill( $skill ); ?>
            </div>
        </section>

        <!-- Links -->
        <section class="gdc-resume-builder" data-section="links">
            <div class="gdc-resume-builder-head">
                <h3><?php esc_html_e( 'Links', 'gend-society' ); ?></h3>
                <button type="button" class="gdc-resume-add" data-add="links">+ <?php esc_html_e( 'Add', 'gend-society' ); ?></button>
            </div>
            <div class="gdc-resume-rows" data-rows="links">
                <?php foreach ( $resume['links'] as $row ) gdc_resume_row_link( $row ); ?>
            </div>
        </section>

        <!-- Row templates (cloned by JS for new rows) -->
        <template data-tpl="experience"><?php gdc_resume_row_experience( array() ); ?></template>
        <template data-tpl="education"><?php gdc_resume_row_education( array() ); ?></template>
        <template data-tpl="skills"><?php gdc_resume_row_skill( '' ); ?></template>
        <template data-tpl="links"><?php gdc_resume_row_link( array() ); ?></template>
    </div>
    <?php
    gdc_resume_editor_script();
}

function gdc_resume_row_experience( $row ) {
    ?>
    <div class="gdc-resume-row" data-row="experience">
        <div class="gdc-resume-row-grid">
            <input type="text" data-f="role"    placeholder="<?php esc_attr_e( 'Role / Title', 'gend-society' ); ?>"   value="<?php echo esc_attr( $row['role'] ?? '' ); ?>" />
            <input type="text" data-f="company" placeholder="<?php esc_attr_e( 'Company', 'gend-society' ); ?>"        value="<?php echo esc_attr( $row['company'] ?? '' ); ?>" />
            <input type="text" data-f="start"   placeholder="<?php esc_attr_e( 'Start (e.g. 2023)', 'gend-society' ); ?>" value="<?php echo esc_attr( $row['start'] ?? '' ); ?>" />
            <input type="text" data-f="end"     placeholder="<?php esc_attr_e( 'End (e.g. Present)', 'gend-society' ); ?>" value="<?php echo esc_attr( $row['end'] ?? '' ); ?>" />
        </div>
        <textarea data-f="notes" rows="2" placeholder="<?php esc_attr_e( 'Highlights / responsibilities…', 'gend-society' ); ?>"><?php echo esc_textarea( $row['notes'] ?? '' ); ?></textarea>
        <button type="button" class="gdc-resume-remove" data-remove>&times;</button>
    </div>
    <?php
}

function gdc_resume_row_education( $row ) {
    ?>
    <div class="gdc-resume-row" data-row="education">
        <div class="gdc-resume-row-grid">
            <input type="text" data-f="credential" placeholder="<?php esc_attr_e( 'Credential / Degree', 'gend-society' ); ?>" value="<?php echo esc_attr( $row['credential'] ?? '' ); ?>" />
            <input type="text" data-f="school"     placeholder="<?php esc_attr_e( 'School', 'gend-society' ); ?>"             value="<?php echo esc_attr( $row['school'] ?? '' ); ?>" />
            <input type="text" data-f="year"       placeholder="<?php esc_attr_e( 'Year', 'gend-society' ); ?>"               value="<?php echo esc_attr( $row['year'] ?? '' ); ?>" />
        </div>
        <button type="button" class="gdc-resume-remove" data-remove>&times;</button>
    </div>
    <?php
}

function gdc_resume_row_skill( $skill ) {
    ?>
    <div class="gdc-resume-row gdc-resume-row--inline" data-row="skills">
        <input type="text" data-f="value" placeholder="<?php esc_attr_e( 'Skill', 'gend-society' ); ?>" value="<?php echo esc_attr( is_array( $skill ) ? '' : $skill ); ?>" />
        <button type="button" class="gdc-resume-remove" data-remove>&times;</button>
    </div>
    <?php
}

function gdc_resume_row_link( $row ) {
    ?>
    <div class="gdc-resume-row gdc-resume-row--inline" data-row="links">
        <input type="text" data-f="label" placeholder="<?php esc_attr_e( 'Label', 'gend-society' ); ?>" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" />
        <input type="url"  data-f="url"   placeholder="https://…" value="<?php echo esc_attr( $row['url'] ?? '' ); ?>" />
        <button type="button" class="gdc-resume-remove" data-remove>&times;</button>
    </div>
    <?php
}

/**
 * Resume CSS — emitted once per request. Matches the obsidian/magenta palette.
 */
function gdc_resume_panel_styles() {
    static $done = false;
    if ( $done ) return;
    $done = true;
    ?>
    <style>
    .gdc-resume { color:#cbd5e1; font-family:"Inter",sans-serif; padding-top:6px; }
    .gdc-resume-bar { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:6px; }
    .gdc-resume-title { color:#fff; font-size:1.25rem; font-weight:900; margin:0; letter-spacing:0.5px; }
    .gdc-resume-sub { color:#64748b; font-size:0.85rem; margin:4px 0 0; }
    .gdc-resume-save { background:#b608c9; border:0; color:#fff; font-weight:800; letter-spacing:1px; text-transform:uppercase; font-size:0.78rem; padding:12px 24px; border-radius:100px; cursor:pointer; transition:transform 0.18s, box-shadow 0.18s, opacity 0.18s; }
    .gdc-resume-save:hover { transform:translateY(-2px); box-shadow:0 10px 24px rgba(182,8,201,0.35); }
    .gdc-resume-save[disabled] { opacity:0.55; cursor:default; transform:none; box-shadow:none; }
    .gdc-resume-status { min-height:18px; font-size:0.82rem; margin:8px 0 18px; color:#89C2E0; }
    .gdc-resume-status.is-error { color:#f87171; }

    .gdc-resume-field { margin-bottom:18px; }
    .gdc-resume-field > label { display:block; color:#94a3b8; font-size:0.72rem; font-weight:700; letter-spacing:1.4px; text-transform:uppercase; margin-bottom:7px; }
    .gdc-resume input[type="text"], .gdc-resume input[type="url"], .gdc-resume textarea {
        width:100%; box-sizing:border-box; background:rgba(11,14,20,0.7); border:1px solid rgba(255,255,255,0.12);
        border-radius:10px; padding:11px 14px; color:#f8fafc; font-size:0.92rem; font-family:inherit;
    }
    .gdc-resume input:focus, .gdc-resume textarea:focus { outline:none; border-color:rgba(182,8,201,0.55); }
    .gdc-resume textarea { resize:vertical; }

    .gdc-resume-builder { margin:26px 0; border-top:1px solid rgba(255,255,255,0.07); padding-top:18px; }
    .gdc-resume-builder-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .gdc-resume-builder-head h3 { color:#fff; font-size:1rem; font-weight:800; margin:0; letter-spacing:0.5px; }
    .gdc-resume-add { background:rgba(182,8,201,0.12); border:1px solid rgba(182,8,201,0.4); color:#d56ee0; font-weight:700; font-size:0.78rem; padding:7px 16px; border-radius:8px; cursor:pointer; }
    .gdc-resume-add:hover { background:rgba(182,8,201,0.22); color:#fff; }

    .gdc-resume-rows { display:flex; flex-direction:column; gap:12px; }
    .gdc-resume-row { position:relative; background:rgba(11,14,20,0.45); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:16px 44px 16px 16px; }
    .gdc-resume-row-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:10px; }
    .gdc-resume-row--inline { display:flex; gap:10px; align-items:center; }
    .gdc-resume-row--inline input { flex:1 1 auto; }
    .gdc-resume-remove { position:absolute; top:10px; right:10px; width:26px; height:26px; line-height:1; background:transparent; border:1px solid rgba(255,255,255,0.14); color:#94a3b8; border-radius:8px; cursor:pointer; font-size:1rem; }
    .gdc-resume-remove:hover { color:#fff; border-color:#f87171; background:rgba(248,113,113,0.12); }
    .gdc-resume-row--inline .gdc-resume-remove { position:static; flex:0 0 auto; }

    /* Read-only view */
    .gdc-resume-headline { color:#fff; font-size:1.5rem; font-weight:900; margin:0 0 8px; }
    .gdc-resume-summary { color:#cbd5e1; font-size:1rem; line-height:1.65; margin:0 0 22px; }
    .gdc-resume-section { margin:22px 0; border-top:1px solid rgba(255,255,255,0.07); padding-top:16px; }
    .gdc-resume-section h3 { color:#b608c9; font-size:0.78rem; font-weight:800; letter-spacing:2px; text-transform:uppercase; margin:0 0 14px; }
    .gdc-resume-entry { margin-bottom:16px; }
    .gdc-resume-entry-head { display:flex; flex-wrap:wrap; align-items:baseline; gap:10px; }
    .gdc-resume-entry-head strong { color:#fff; font-size:1rem; }
    .gdc-resume-at { color:#89C2E0; font-size:0.9rem; }
    .gdc-resume-dates { color:#64748b; font-size:0.8rem; margin-left:auto; }
    .gdc-resume-notes { color:#cbd5e1; font-size:0.9rem; line-height:1.55; margin:6px 0 0; }
    .gdc-resume-skills { display:flex; flex-wrap:wrap; gap:8px; }
    .gdc-resume-skill { background:rgba(137,194,224,0.1); border:1px solid rgba(137,194,224,0.3); color:#89C2E0; font-size:0.82rem; font-weight:600; padding:6px 14px; border-radius:100px; }
    .gdc-resume-links { display:flex; flex-wrap:wrap; gap:10px; }
    .gdc-resume-link { color:#89C2E0; text-decoration:none; border:1px solid rgba(137,194,224,0.3); padding:8px 16px; border-radius:10px; font-size:0.88rem; }
    .gdc-resume-link:hover { color:#fff; border-color:#b608c9; background:rgba(182,8,201,0.12); }

    @media (max-width:680px) { .gdc-resume-row-grid { grid-template-columns:1fr; } }
    </style>
    <?php
}

/**
 * Editor JS — collects the form into JSON and POSTs to admin-ajax.
 */
function gdc_resume_editor_script() {
    ?>
    <script>
    (function () {
        var root = document.getElementById('gdc-resume-editor');
        if (!root || root.dataset.wired) return;
        root.dataset.wired = '1';

        var ajaxUrl = root.getAttribute('data-ajax');
        var nonce   = root.getAttribute('data-nonce');
        var saveBtn = document.getElementById('gdc-resume-save');
        var statusEl= document.getElementById('gdc-resume-status');

        // Add row (clone the matching <template>)
        root.addEventListener('click', function (e) {
            var add = e.target.closest('[data-add]');
            if (add) {
                var key = add.getAttribute('data-add');
                var tpl = root.querySelector('template[data-tpl="' + key + '"]');
                var rows = root.querySelector('[data-rows="' + key + '"]');
                if (tpl && rows) {
                    rows.appendChild(tpl.content.cloneNode(true));
                    var added = rows.lastElementChild;
                    if (added) { var f = added.querySelector('input,textarea'); if (f) f.focus(); }
                }
                return;
            }
            var rm = e.target.closest('[data-remove]');
            if (rm) {
                var row = rm.closest('.gdc-resume-row');
                if (row) row.remove();
            }
        });

        function collect() {
            var data = {
                headline: (document.getElementById('gdc-resume-headline').value || '').trim(),
                summary:  (document.getElementById('gdc-resume-summary').value || '').trim(),
                experience: [], education: [], skills: [], links: []
            };
            root.querySelectorAll('[data-rows="experience"] .gdc-resume-row').forEach(function (r) {
                data.experience.push({
                    role:    val(r, 'role'),
                    company: val(r, 'company'),
                    start:   val(r, 'start'),
                    end:     val(r, 'end'),
                    notes:   val(r, 'notes')
                });
            });
            root.querySelectorAll('[data-rows="education"] .gdc-resume-row').forEach(function (r) {
                data.education.push({
                    credential: val(r, 'credential'),
                    school:     val(r, 'school'),
                    year:       val(r, 'year')
                });
            });
            root.querySelectorAll('[data-rows="skills"] .gdc-resume-row').forEach(function (r) {
                data.skills.push(val(r, 'value'));
            });
            root.querySelectorAll('[data-rows="links"] .gdc-resume-row').forEach(function (r) {
                data.links.push({ label: val(r, 'label'), url: val(r, 'url') });
            });
            return data;
        }
        function val(row, f) {
            var el = row.querySelector('[data-f="' + f + '"]');
            return el ? (el.value || '').trim() : '';
        }

        saveBtn.addEventListener('click', function () {
            saveBtn.disabled = true;
            statusEl.className = 'gdc-resume-status';
            statusEl.textContent = '<?php echo esc_js( __( 'Saving…', 'gend-society' ) ); ?>';

            var body = new URLSearchParams();
            body.set('action', 'gdc_save_resume');
            body.set('nonce', nonce);
            body.set('data', JSON.stringify(collect()));

            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    saveBtn.disabled = false;
                    if (json && json.success) {
                        statusEl.className = 'gdc-resume-status';
                        statusEl.textContent = '<?php echo esc_js( __( 'Resume saved.', 'gend-society' ) ); ?>';
                    } else {
                        statusEl.className = 'gdc-resume-status is-error';
                        statusEl.textContent = (json && json.data && json.data.message) ? json.data.message : '<?php echo esc_js( __( 'Save failed.', 'gend-society' ) ); ?>';
                    }
                })
                .catch(function () {
                    saveBtn.disabled = false;
                    statusEl.className = 'gdc-resume-status is-error';
                    statusEl.textContent = '<?php echo esc_js( __( 'Network error — try again.', 'gend-society' ) ); ?>';
                });
        });
    }());
    </script>
    <?php
}
