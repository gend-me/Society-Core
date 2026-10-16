<?php
/**
 * Member Resume — structured resume builder for the Overview › Resume sub-tab.
 *
 * Storage: a single user_meta key `_gend_society_resume` holding a sanitized array:
 *   headline, summary,
 *   experience[] { company, group_id, logo_id, role, company_desc, start, end, notes },
 *   education[]  { school, credential, year, logo_id },
 *   skills[]     (shown as "Licenses & Credentials"),
 *   links[]      { label, url }
 *
 * "App owner" (above Experience) isn't stored: it lists the Integration Hubs the member administers that have a
 * connected app (group icon, site name, View Site).
 *
 * The owner edits inline (Edit / Visitor view switch, Save via admin-ajax); other members see the visitor view.
 * Company logos for typed-in companies and school logos are uploaded through gend_society_resume_upload (images
 * only, 5 MB, owner's own attachments). Assets: assets/profile-resume.css + .js (kept out of the page content so
 * content filters can't mangle them). Rendered by gend_society_render_resume_panel(), called from the Overview
 * orchestrator in member-profile-pages.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Maximum rows we persist per repeatable section (defensive cap). */
if ( ! defined( 'GEND_SOCIETY_RESUME_MAX_ROWS' ) ) define( 'GEND_SOCIETY_RESUME_MAX_ROWS', 40 );

/**
 * Fetch a user's saved resume, normalized to the expected shape.
 */
function gend_society_get_resume( $user_id ) {
    $data = get_user_meta( (int) $user_id, '_gend_society_resume', true );
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

/** An image attachment the user uploaded themselves (0 otherwise). */
function gend_society_resume_own_image( $id, $user_id ) {
    $id = absint( $id );
    if ( ! $id || ! wp_attachment_is_image( $id ) ) return 0;
    return (int) get_post_field( 'post_author', $id ) === (int) $user_id ? $id : 0;
}

/**
 * Sanitize an incoming resume payload (from the AJAX save) into the stored shape.
 */
function gend_society_sanitize_resume( $data, $user_id = 0 ) {
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    $my_groups = gend_society_resume_member_groups( $user_id );
    $out = array(
        'headline'   => sanitize_text_field( $data['headline'] ?? '' ),
        'summary'    => sanitize_textarea_field( $data['summary'] ?? '' ),
        'experience' => array(),
        'education'  => array(),
        'skills'     => array(),
        'links'      => array(),
    );

    if ( ! empty( $data['experience'] ) && is_array( $data['experience'] ) ) {
        foreach ( array_slice( $data['experience'], 0, GEND_SOCIETY_RESUME_MAX_ROWS ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $group_id     = absint( $row['group_id'] ?? 0 );
            if ( $group_id && ! isset( $my_groups[ $group_id ] ) ) $group_id = 0;   // only groups they belong to
            $company      = $group_id ? $my_groups[ $group_id ]['name'] : sanitize_text_field( $row['company'] ?? '' );
            $logo_id      = $group_id ? 0 : gend_society_resume_own_image( $row['logo_id'] ?? 0, $user_id );
            $role         = sanitize_text_field( $row['role'] ?? '' );
            $company_desc = sanitize_textarea_field( $row['company_desc'] ?? '' );
            $start        = sanitize_text_field( $row['start'] ?? '' );
            $end          = sanitize_text_field( $row['end'] ?? '' );
            $notes        = sanitize_textarea_field( $row['notes'] ?? '' );
            if ( $role === '' && $company === '' && $notes === '' ) continue;
            $out['experience'][] = compact( 'company', 'group_id', 'logo_id', 'role', 'company_desc', 'start', 'end', 'notes' );
        }
    }

    if ( ! empty( $data['education'] ) && is_array( $data['education'] ) ) {
        foreach ( array_slice( $data['education'], 0, GEND_SOCIETY_RESUME_MAX_ROWS ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $school     = sanitize_text_field( $row['school'] ?? '' );
            $credential = sanitize_text_field( $row['credential'] ?? '' );
            $year       = sanitize_text_field( $row['year'] ?? '' );
            $logo_id    = gend_society_resume_own_image( $row['logo_id'] ?? 0, $user_id );
            if ( $school === '' && $credential === '' ) continue;
            $out['education'][] = compact( 'school', 'credential', 'year', 'logo_id' );
        }
    }

    if ( ! empty( $data['skills'] ) && is_array( $data['skills'] ) ) {
        foreach ( array_slice( $data['skills'], 0, GEND_SOCIETY_RESUME_MAX_ROWS ) as $skill ) {
            $skill = sanitize_text_field( is_array( $skill ) ? '' : $skill );
            if ( $skill !== '' ) $out['skills'][] = $skill;
        }
    }

    if ( ! empty( $data['links'] ) && is_array( $data['links'] ) ) {
        foreach ( array_slice( $data['links'], 0, GEND_SOCIETY_RESUME_MAX_ROWS ) as $row ) {
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
 * The groups (Integration Hubs) a member belongs to: [id => ['name', 'avatar']].
 */
function gend_society_resume_member_groups( $user_id ) {
    static $cache = array();
    $user_id = (int) $user_id;
    if ( isset( $cache[ $user_id ] ) ) return $cache[ $user_id ];
    $out = array();
    if ( $user_id && function_exists( 'groups_get_groups' ) ) {
        $r = groups_get_groups( array( 'user_id' => $user_id, 'per_page' => 200, 'orderby' => 'name', 'order' => 'ASC', 'show_hidden' => true ) );
        foreach ( (array) ( $r['groups'] ?? array() ) as $g ) {
            $out[ (int) $g->id ] = array(
                'name'   => (string) $g->name,
                'avatar' => function_exists( 'bp_core_fetch_avatar' ) ? (string) bp_core_fetch_avatar( array( 'item_id' => (int) $g->id, 'object' => 'group', 'type' => 'thumb', 'html' => false ) ) : '',
            );
        }
    }
    return $cache[ $user_id ] = $out;
}

/**
 * Apps the member launched: Integration Hubs they administer that have a connected app.
 * [ ['group' => name, 'icon' => group avatar, 'name' => site name, 'url' => site URL] ]
 */
function gend_society_resume_owned_apps( $user_id ) {
    $apps = array();
    if ( ! function_exists( 'groups_is_user_admin' ) || ! function_exists( 'psoo_get_group_apps' ) ) return $apps;
    $seen = array();
    foreach ( gend_society_resume_member_groups( $user_id ) as $gid => $g ) {
        if ( ! groups_is_user_admin( (int) $user_id, (int) $gid ) ) continue;
        foreach ( (array) psoo_get_group_apps( (int) $gid ) as $app ) {
            $app = (array) $app;
            $url = isset( $app['link'] ) ? (string) $app['link'] : '';
            if ( $url === '' || isset( $seen[ $url ] ) ) continue;
            $seen[ $url ] = true;
            $apps[] = array(
                'group' => $g['name'],
                'icon'  => function_exists( 'bp_core_fetch_avatar' ) ? (string) bp_core_fetch_avatar( array( 'item_id' => (int) $gid, 'object' => 'group', 'type' => 'full', 'html' => false ) ) : $g['avatar'],
                'name'  => isset( $app['name'] ) && $app['name'] !== '' ? (string) $app['name'] : $g['name'],
                'url'   => $url,
            );
        }
    }
    return $apps;
}

/** Image URL for an experience row: the group's picture, or the uploaded company logo. */
function gend_society_resume_company_image( $row ) {
    $gid = (int) ( $row['group_id'] ?? 0 );
    if ( $gid && function_exists( 'bp_core_fetch_avatar' ) ) {
        return (string) bp_core_fetch_avatar( array( 'item_id' => $gid, 'object' => 'group', 'type' => 'full', 'html' => false ) );
    }
    $logo = (int) ( $row['logo_id'] ?? 0 );
    return $logo ? (string) wp_get_attachment_image_url( $logo, 'thumbnail' ) : '';
}

/**
 * AJAX: save the current user's resume.
 */
add_action( 'wp_ajax_gend_society_save_resume', 'gend_society_ajax_save_resume' );
function gend_society_ajax_save_resume() {
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
    $clean = gend_society_sanitize_resume( $data, $uid );
    update_user_meta( $uid, '_gend_society_resume', $clean );
    ob_start();
    gend_society_render_resume_readonly( $clean, $uid );
    wp_send_json_success( array( 'message' => 'Saved.', 'preview' => ob_get_clean() ) );
}

/**
 * AJAX: upload a company / school logo (images only, 5 MB) for the current user's resume.
 */
add_action( 'wp_ajax_gend_society_resume_upload', 'gend_society_ajax_resume_upload' );
function gend_society_ajax_resume_upload() {
    check_ajax_referer( 'gdc_resume', 'nonce' );
    $uid = get_current_user_id();
    if ( ! $uid ) wp_send_json_error( array( 'message' => 'Not logged in.' ), 403 );
    if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) ) {
        wp_send_json_error( array( 'message' => __( 'No image received.', 'gend-society' ) ), 400 );
    }
    if ( (int) $_FILES['file']['size'] > 5 * MB_IN_BYTES ) {
        wp_send_json_error( array( 'message' => __( 'Images can be up to 5 MB.', 'gend-society' ) ), 400 );
    }
    $check = wp_check_filetype_and_ext( $_FILES['file']['tmp_name'], $_FILES['file']['name'] );
    $types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
    if ( empty( $check['type'] ) || ! in_array( $check['type'], $types, true ) || ! @getimagesize( $_FILES['file']['tmp_name'] ) ) {
        wp_send_json_error( array( 'message' => __( 'Choose a JPG, PNG, GIF or WebP image.', 'gend-society' ) ), 400 );
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $id = media_handle_upload( 'file', 0, array( 'post_author' => $uid ), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ) ) );
    if ( is_wp_error( $id ) ) wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
    wp_send_json_success( array( 'id' => (int) $id, 'url' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ) ) );
}

/** Enqueue the resume assets (once). */
function gend_society_resume_assets( $is_own ) {
    static $css_done = false, $js_done = false;
    $css = GEND_SOCIETY_DIR . 'assets/profile-resume.css';
    // Printed here (the panel renders after wp_head) so the styles apply without a flash.
    if ( ! $css_done ) {
        $css_done = true;
        echo '<link rel="stylesheet" id="gend-society-resume-css" href="' . esc_url( GEND_SOCIETY_URL . 'assets/profile-resume.css?ver=' . GEND_SOCIETY_VERSION . '.' . ( file_exists( $css ) ? filemtime( $css ) : '0' ) ) . '" media="all" />';
    }
    if ( $is_own && ! $js_done ) {
        $js_done = true;
        $js = GEND_SOCIETY_DIR . 'assets/profile-resume.js';
        wp_enqueue_script( 'gend-society-resume', GEND_SOCIETY_URL . 'assets/profile-resume.js', array(), GEND_SOCIETY_VERSION . '.' . @filemtime( $js ), true );
    }
}

/**
 * Render the Resume panel. Editable for the owner (with a visitor-view switch); the visitor view for everyone else.
 */
function gend_society_render_resume_panel( $displayed_user_id, $is_own ) {
    $displayed_user_id = (int) $displayed_user_id;
    $resume = gend_society_get_resume( $displayed_user_id );

    gend_society_resume_assets( $is_own );

    if ( $is_own ) {
        gend_society_render_resume_editor( $resume, $displayed_user_id );
    } else {
        echo '<div class="gdc-resume-wrap">';
        gend_society_render_resume_readonly( $resume, $displayed_user_id );
        echo '</div>';
    }
}

/**
 * Visitor view — the published resume.
 */
function gend_society_render_resume_readonly( $resume, $user_id ) {
    $user_id = (int) $user_id;
    $apps = gend_society_resume_owned_apps( $user_id );
    $is_empty = $resume['headline'] === '' && $resume['summary'] === ''
        && empty( $resume['experience'] ) && empty( $resume['education'] )
        && empty( $resume['skills'] ) && empty( $resume['links'] ) && empty( $apps );

    if ( $is_empty ) {
        echo '<div class="gdc-cv gdc-cv--empty"><p>' . esc_html__( 'This member has not published a resume yet.', 'gend-society' ) . '</p></div>';
        return;
    }
    $user   = get_userdata( $user_id );
    $name   = $user ? $user->display_name : '';
    $avatar = function_exists( 'bp_core_fetch_avatar' ) ? bp_core_fetch_avatar( array( 'item_id' => $user_id, 'object' => 'user', 'type' => 'full', 'html' => false ) ) : get_avatar_url( $user_id, array( 'size' => 240 ) );
    ?>
    <article class="gdc-cv">
        <header class="gdc-cv-hero">
            <div class="gdc-cv-hero__glow" aria-hidden="true"></div>
            <?php if ( $avatar ) : ?><img class="gdc-cv-hero__avatar" src="<?php echo esc_url( $avatar ); ?>" alt="" loading="lazy"><?php endif; ?>
            <div class="gdc-cv-hero__text">
                <?php if ( $name !== '' ) : ?><h2 class="gdc-cv-hero__name"><?php echo esc_html( $name ); ?></h2><?php endif; ?>
                <?php if ( $resume['headline'] !== '' ) : ?><p class="gdc-cv-hero__headline"><?php echo esc_html( $resume['headline'] ); ?></p><?php endif; ?>
                <?php if ( ! empty( $resume['links'] ) ) : ?>
                <div class="gdc-cv-hero__links">
                    <?php foreach ( $resume['links'] as $row ) : ?>
                        <a href="<?php echo esc_url( $row['url'] ?? '' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( ( $row['label'] ?? '' ) !== '' ? $row['label'] : preg_replace( '#^https?://(www\.)?#', '', $row['url'] ) ); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </header>

        <?php if ( $resume['summary'] !== '' ) : ?>
        <section class="gdc-cv-section gdc-cv-summary">
            <h3><?php esc_html_e( 'Profile', 'gend-society' ); ?></h3>
            <p><?php echo nl2br( esc_html( $resume['summary'] ) ); ?></p>
        </section>
        <?php endif; ?>

        <?php if ( $apps ) : ?>
        <section class="gdc-cv-section">
            <h3><?php esc_html_e( 'App owner', 'gend-society' ); ?></h3>
            <div class="gdc-cv-apps">
                <?php foreach ( $apps as $a ) : ?>
                <div class="gdc-cv-app">
                    <?php if ( $a['icon'] ) : ?><img src="<?php echo esc_url( $a['icon'] ); ?>" alt="" loading="lazy"><?php endif; ?>
                    <div class="gdc-cv-app__meta">
                        <strong><?php echo esc_html( $a['name'] ); ?></strong>
                        <small><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $a['url'] ) ) ); ?></small>
                    </div>
                    <a class="gdc-cv-btn" href="<?php echo esc_url( $a['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View Site', 'gend-society' ); ?></a>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ( ! empty( $resume['experience'] ) ) : ?>
        <section class="gdc-cv-section">
            <h3><?php esc_html_e( 'Experience', 'gend-society' ); ?></h3>
            <ol class="gdc-cv-timeline">
                <?php foreach ( $resume['experience'] as $row ) :
                    $img = gend_society_resume_company_image( $row );
                    $company = (string) ( $row['company'] ?? '' );
                    $range = trim( ( $row['start'] ?? '' ) . ( ! empty( $row['end'] ) ? ' – ' . $row['end'] : '' ) );
                ?>
                <li class="gdc-cv-job">
                    <span class="gdc-cv-job__logo"><?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy"><?php else : ?><?php echo esc_html( strtoupper( substr( $company !== '' ? $company : ( $row['role'] ?? '?' ), 0, 1 ) ) ); ?><?php endif; ?></span>
                    <div class="gdc-cv-job__body">
                        <div class="gdc-cv-job__head">
                            <div>
                                <?php if ( ! empty( $row['role'] ) ) : ?><h4><?php echo esc_html( $row['role'] ); ?></h4><?php endif; ?>
                                <?php if ( $company !== '' ) : ?><span class="gdc-cv-job__company"><?php echo esc_html( $company ); ?></span><?php endif; ?>
                            </div>
                            <?php if ( $range !== '' ) : ?><span class="gdc-cv-dates"><?php echo esc_html( $range ); ?></span><?php endif; ?>
                        </div>
                        <?php if ( ! empty( $row['company_desc'] ) ) : ?><p class="gdc-cv-job__about"><?php echo nl2br( esc_html( $row['company_desc'] ) ); ?></p><?php endif; ?>
                        <?php if ( ! empty( $row['notes'] ) ) :
                            $lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $row['notes'] ) ) );
                        ?>
                            <?php if ( count( $lines ) > 1 ) : ?>
                            <ul class="gdc-cv-job__points"><?php foreach ( $lines as $l ) : ?><li><?php echo esc_html( ltrim( $l, "-•* \t" ) ); ?></li><?php endforeach; ?></ul>
                            <?php else : ?>
                            <p class="gdc-cv-job__notes"><?php echo esc_html( $row['notes'] ); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php endif; ?>

        <?php if ( ! empty( $resume['education'] ) ) : ?>
        <section class="gdc-cv-section">
            <h3><?php esc_html_e( 'Education', 'gend-society' ); ?></h3>
            <div class="gdc-cv-edu">
                <?php foreach ( $resume['education'] as $row ) :
                    $logo = ! empty( $row['logo_id'] ) ? wp_get_attachment_image_url( (int) $row['logo_id'], 'thumbnail' ) : '';
                    $school = (string) ( $row['school'] ?? '' );
                ?>
                <div class="gdc-cv-school">
                    <span class="gdc-cv-job__logo"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="" loading="lazy"><?php else : ?><?php echo esc_html( strtoupper( substr( $school !== '' ? $school : '?', 0, 1 ) ) ); ?><?php endif; ?></span>
                    <div>
                        <?php if ( ! empty( $row['credential'] ) ) : ?><h4><?php echo esc_html( $row['credential'] ); ?></h4><?php endif; ?>
                        <?php if ( $school !== '' ) : ?><span class="gdc-cv-job__company"><?php echo esc_html( $school ); ?></span><?php endif; ?>
                        <?php if ( ! empty( $row['year'] ) ) : ?><span class="gdc-cv-dates"><?php echo esc_html( $row['year'] ); ?></span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ( ! empty( $resume['skills'] ) ) : ?>
        <section class="gdc-cv-section">
            <h3><?php esc_html_e( 'Licenses & Credentials', 'gend-society' ); ?></h3>
            <div class="gdc-cv-chips">
                <?php foreach ( $resume['skills'] as $skill ) : ?><span><?php echo esc_html( $skill ); ?></span><?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </article>
    <?php
}

/**
 * Editable resume (profile owner), with an Edit / Visitor view switch.
 */
function gend_society_render_resume_editor( $resume, $user_id ) {
    $nonce    = wp_create_nonce( 'gdc_resume' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    $groups   = array();
    foreach ( gend_society_resume_member_groups( $user_id ) as $gid => $g ) $groups[] = array( 'id' => (int) $gid, 'name' => $g['name'], 'avatar' => $g['avatar'] );
    $apps = gend_society_resume_owned_apps( $user_id );
    ?>
    <div class="gdc-resume-wrap">
    <div class="gdc-resume gdc-resume--edit" id="gdc-resume-editor"
         data-ajax="<?php echo esc_url( $ajax_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"
         data-groups="<?php echo esc_attr( wp_json_encode( $groups ) ); ?>">

        <div class="gdc-resume-bar">
            <div>
                <h2 class="gdc-resume-title"><?php esc_html_e( 'Your Resume', 'gend-society' ); ?></h2>
                <p class="gdc-resume-sub"><?php esc_html_e( 'Build out your professional history. Visible on your public profile.', 'gend-society' ); ?></p>
            </div>
            <div class="gdc-resume-bar__actions">
                <div class="gdc-resume-mode" role="tablist" aria-label="<?php esc_attr_e( 'Resume view', 'gend-society' ); ?>">
                    <button type="button" class="is-active" data-mode="edit" role="tab" aria-selected="true"><?php esc_html_e( 'Edit', 'gend-society' ); ?></button>
                    <button type="button" data-mode="preview" role="tab" aria-selected="false"><?php esc_html_e( 'Visitor view', 'gend-society' ); ?></button>
                </div>
                <button type="button" class="gdc-resume-save" id="gdc-resume-save"><?php esc_html_e( 'Save Resume', 'gend-society' ); ?></button>
            </div>
        </div>
        <p class="gdc-resume-status" id="gdc-resume-status" aria-live="polite"></p>

        <div class="gdc-resume-preview" data-preview hidden>
            <p class="gdc-resume-preview__note"><?php esc_html_e( 'This is how visitors see your resume (as last saved).', 'gend-society' ); ?></p>
            <div data-preview-body><?php gend_society_render_resume_readonly( $resume, $user_id ); ?></div>
        </div>

        <div data-editor>
        <div class="gdc-resume-field">
            <label for="gdc-resume-headline"><?php esc_html_e( 'Headline', 'gend-society' ); ?></label>
            <input type="text" id="gdc-resume-headline" maxlength="160"
                   placeholder="<?php esc_attr_e( 'e.g. Senior Solidity Developer', 'gend-society' ); ?>"
                   value="<?php echo esc_attr( $resume['headline'] ); ?>" />
        </div>

        <div class="gdc-resume-field">
            <label for="gdc-resume-summary"><?php esc_html_e( 'Summary', 'gend-society' ); ?></label>
            <textarea id="gdc-resume-summary" rows="4"
                      placeholder="<?php esc_attr_e( 'A short professional summary…', 'gend-society' ); ?>"><?php echo esc_textarea( $resume['summary'] ); ?></textarea>
        </div>

        <!-- App owner (automatic: the Integration Hubs you administer with a connected app) -->
        <section class="gdc-resume-builder" data-section="apps">
            <div class="gdc-resume-builder-head">
                <h3><?php esc_html_e( 'App owner', 'gend-society' ); ?></h3>
            </div>
            <?php if ( $apps ) : ?>
            <div class="gdc-cv-apps">
                <?php foreach ( $apps as $a ) : ?>
                <div class="gdc-cv-app">
                    <?php if ( $a['icon'] ) : ?><img src="<?php echo esc_url( $a['icon'] ); ?>" alt="" loading="lazy"><?php endif; ?>
                    <div class="gdc-cv-app__meta"><strong><?php echo esc_html( $a['name'] ); ?></strong><small><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $a['url'] ) ) ); ?></small></div>
                    <a class="gdc-cv-btn" href="<?php echo esc_url( $a['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View Site', 'gend-society' ); ?></a>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="gdc-resume-hint"><?php esc_html_e( 'Listed automatically from the Integration Hubs you run with a connected app.', 'gend-society' ); ?></p>
            <?php else : ?>
            <p class="gdc-resume-hint"><?php esc_html_e( 'Apps you launch on gend.me appear here automatically.', 'gend-society' ); ?></p>
            <?php endif; ?>
        </section>

        <!-- Experience -->
        <section class="gdc-resume-builder" data-section="experience">
            <div class="gdc-resume-builder-head">
                <h3><?php esc_html_e( 'Experience', 'gend-society' ); ?></h3>
                <button type="button" class="gdc-resume-add" data-add="experience">+ <?php esc_html_e( 'Add', 'gend-society' ); ?></button>
            </div>
            <div class="gdc-resume-rows" data-rows="experience">
                <?php foreach ( $resume['experience'] as $row ) gend_society_resume_row_experience( $row ); ?>
            </div>
        </section>

        <!-- Education -->
        <section class="gdc-resume-builder" data-section="education">
            <div class="gdc-resume-builder-head">
                <h3><?php esc_html_e( 'Education', 'gend-society' ); ?></h3>
                <button type="button" class="gdc-resume-add" data-add="education">+ <?php esc_html_e( 'Add', 'gend-society' ); ?></button>
            </div>
            <div class="gdc-resume-rows" data-rows="education">
                <?php foreach ( $resume['education'] as $row ) gend_society_resume_row_education( $row ); ?>
            </div>
        </section>

        <!-- Licenses & Credentials (stored as `skills`) -->
        <section class="gdc-resume-builder" data-section="skills">
            <div class="gdc-resume-builder-head">
                <h3><?php esc_html_e( 'Licenses & Credentials', 'gend-society' ); ?></h3>
                <button type="button" class="gdc-resume-add" data-add="skills">+ <?php esc_html_e( 'Add', 'gend-society' ); ?></button>
            </div>
            <div class="gdc-resume-rows" data-rows="skills">
                <?php foreach ( $resume['skills'] as $skill ) gend_society_resume_row_skill( $skill ); ?>
            </div>
        </section>

        <!-- Links -->
        <section class="gdc-resume-builder" data-section="links">
            <div class="gdc-resume-builder-head">
                <h3><?php esc_html_e( 'Links', 'gend-society' ); ?></h3>
                <button type="button" class="gdc-resume-add" data-add="links">+ <?php esc_html_e( 'Add', 'gend-society' ); ?></button>
            </div>
            <div class="gdc-resume-rows" data-rows="links">
                <?php foreach ( $resume['links'] as $row ) gend_society_resume_row_link( $row ); ?>
            </div>
        </section>
        </div>

        <!-- Row templates (cloned by JS for new rows) -->
        <template data-tpl="experience"><?php gend_society_resume_row_experience( array() ); ?></template>
        <template data-tpl="education"><?php gend_society_resume_row_education( array() ); ?></template>
        <template data-tpl="skills"><?php gend_society_resume_row_skill( '' ); ?></template>
        <template data-tpl="links"><?php gend_society_resume_row_link( array() ); ?></template>
    </div>
    </div>
    <?php
}

/** Image picker cell (company / school logo): preview + upload, stores an attachment id. */
function gend_society_resume_logo_field( $logo_id, $label ) {
    $url = $logo_id ? wp_get_attachment_image_url( (int) $logo_id, 'thumbnail' ) : '';
    ?>
    <div class="gdc-resume-logo" data-logo>
        <span class="gdc-resume-logo__img"<?php echo $url ? ' style="background-image:url(' . esc_url( $url ) . ')"' : ''; ?> data-logo-img></span>
        <label class="gdc-resume-logo__btn">
            <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" data-logo-file hidden>
            <span data-logo-label><?php echo $url ? esc_html__( 'Change image', 'gend-society' ) : esc_html( $label ); ?></span>
        </label>
        <button type="button" class="gdc-resume-logo__clear" data-logo-clear<?php echo $url ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'gend-society' ); ?></button>
        <input type="hidden" data-f="logo_id" value="<?php echo esc_attr( (int) $logo_id ); ?>">
    </div>
    <?php
}

function gend_society_resume_row_experience( $row ) {
    $gid     = (int) ( $row['group_id'] ?? 0 );
    $company = (string) ( $row['company'] ?? '' );
    $img     = $row ? gend_society_resume_company_image( $row ) : '';
    ?>
    <div class="gdc-resume-row gdc-resume-row--exp" data-row="experience">
        <div class="gdc-resume-company" data-company>
            <label><?php esc_html_e( 'Company name', 'gend-society' ); ?></label>
            <div class="gdc-resume-company__chosen" data-company-chosen<?php echo ( $company !== '' ) ? '' : ' hidden'; ?>>
                <span class="gdc-resume-company__img"<?php echo $img ? ' style="background-image:url(' . esc_url( $img ) . ')"' : ''; ?> data-company-img><?php echo $img ? '' : esc_html( strtoupper( substr( $company, 0, 1 ) ) ); ?></span>
                <span class="gdc-resume-company__name" data-company-name><?php echo esc_html( $company ); ?></span>
                <span class="gdc-resume-company__tag" data-company-tag><?php echo $gid ? esc_html__( 'Integration Hub', 'gend-society' ) : ''; ?></span>
                <button type="button" class="gdc-resume-company__change" data-company-change><?php esc_html_e( 'Change', 'gend-society' ); ?></button>
            </div>
            <div class="gdc-resume-company__search" data-company-search<?php echo ( $company !== '' ) ? ' hidden' : ''; ?>>
                <input type="text" data-company-q placeholder="<?php esc_attr_e( 'Search your Integration Hubs or type a company name', 'gend-society' ); ?>" autocomplete="off">
                <ul class="gdc-resume-company__results" data-company-results hidden></ul>
            </div>
            <input type="hidden" data-f="company" value="<?php echo esc_attr( $company ); ?>">
            <input type="hidden" data-f="group_id" value="<?php echo esc_attr( $gid ); ?>">
            <div data-company-logo<?php echo ( $company !== '' && ! $gid ) ? '' : ' hidden'; ?>>
                <?php gend_society_resume_logo_field( $gid ? 0 : (int) ( $row['logo_id'] ?? 0 ), __( 'Upload company logo', 'gend-society' ) ); ?>
            </div>
        </div>
        <div class="gdc-resume-field gdc-resume-field--row">
            <label><?php esc_html_e( 'Role / Title', 'gend-society' ); ?></label>
            <input type="text" data-f="role" placeholder="<?php esc_attr_e( 'e.g. Lead Developer', 'gend-society' ); ?>" value="<?php echo esc_attr( $row['role'] ?? '' ); ?>" />
        </div>
        <div class="gdc-resume-field gdc-resume-field--row">
            <label><?php esc_html_e( 'Company description', 'gend-society' ); ?></label>
            <textarea data-f="company_desc" rows="2" placeholder="<?php esc_attr_e( 'What the company does…', 'gend-society' ); ?>"><?php echo esc_textarea( $row['company_desc'] ?? '' ); ?></textarea>
        </div>
        <div class="gdc-resume-row-grid">
            <div class="gdc-resume-field gdc-resume-field--row">
                <label><?php esc_html_e( 'Start date', 'gend-society' ); ?></label>
                <input type="text" data-f="start" placeholder="<?php esc_attr_e( 'e.g. Mar 2023', 'gend-society' ); ?>" value="<?php echo esc_attr( $row['start'] ?? '' ); ?>" />
            </div>
            <div class="gdc-resume-field gdc-resume-field--row">
                <label><?php esc_html_e( 'End date', 'gend-society' ); ?></label>
                <input type="text" data-f="end" placeholder="<?php esc_attr_e( 'e.g. Present', 'gend-society' ); ?>" value="<?php echo esc_attr( $row['end'] ?? '' ); ?>" />
            </div>
        </div>
        <div class="gdc-resume-field gdc-resume-field--row">
            <label><?php esc_html_e( 'Highlights & responsibilities', 'gend-society' ); ?></label>
            <textarea data-f="notes" rows="4" placeholder="<?php esc_attr_e( 'One per line — each becomes a bullet point', 'gend-society' ); ?>"><?php echo esc_textarea( $row['notes'] ?? '' ); ?></textarea>
        </div>
        <button type="button" class="gdc-resume-remove" data-remove aria-label="<?php esc_attr_e( 'Remove', 'gend-society' ); ?>">&times;</button>
    </div>
    <?php
}

function gend_society_resume_row_education( $row ) {
    ?>
    <div class="gdc-resume-row gdc-resume-row--edu" data-row="education">
        <?php gend_society_resume_logo_field( (int) ( $row['logo_id'] ?? 0 ), __( 'Upload school logo', 'gend-society' ) ); ?>
        <div class="gdc-resume-row-grid gdc-resume-row-grid--3">
            <input type="text" data-f="school"     placeholder="<?php esc_attr_e( 'School', 'gend-society' ); ?>"             value="<?php echo esc_attr( $row['school'] ?? '' ); ?>" />
            <input type="text" data-f="credential" placeholder="<?php esc_attr_e( 'Credential / Degree', 'gend-society' ); ?>" value="<?php echo esc_attr( $row['credential'] ?? '' ); ?>" />
            <input type="text" data-f="year"       placeholder="<?php esc_attr_e( 'Year', 'gend-society' ); ?>"               value="<?php echo esc_attr( $row['year'] ?? '' ); ?>" />
        </div>
        <button type="button" class="gdc-resume-remove" data-remove aria-label="<?php esc_attr_e( 'Remove', 'gend-society' ); ?>">&times;</button>
    </div>
    <?php
}

function gend_society_resume_row_skill( $skill ) {
    ?>
    <div class="gdc-resume-row gdc-resume-row--inline" data-row="skills">
        <input type="text" data-f="value" placeholder="<?php esc_attr_e( 'e.g. Certified Scrum Master, Licensed Realtor (ON)', 'gend-society' ); ?>" value="<?php echo esc_attr( is_array( $skill ) ? '' : $skill ); ?>" />
        <button type="button" class="gdc-resume-remove" data-remove aria-label="<?php esc_attr_e( 'Remove', 'gend-society' ); ?>">&times;</button>
    </div>
    <?php
}

function gend_society_resume_row_link( $row ) {
    ?>
    <div class="gdc-resume-row gdc-resume-row--inline" data-row="links">
        <input type="text" data-f="label" placeholder="<?php esc_attr_e( 'Label', 'gend-society' ); ?>" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" />
        <input type="url"  data-f="url"   placeholder="https://…" value="<?php echo esc_attr( $row['url'] ?? '' ); ?>" />
        <button type="button" class="gdc-resume-remove" data-remove aria-label="<?php esc_attr_e( 'Remove', 'gend-society' ); ?>">&times;</button>
    </div>
    <?php
}

/** Kept for callers that print the styles directly; the styles now live in assets/profile-resume.css. */
function gend_society_resume_panel_styles() {
    gend_society_resume_assets( false );
}
