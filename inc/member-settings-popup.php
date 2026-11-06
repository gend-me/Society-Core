<?php
/**
 * Member Settings popup (profile nav gear): one designed page with Profile (photo, cover, profile fields),
 * Account (email / password), Email notifications, Privacy & data, Blocked members and Export data.
 *
 * Opened by the popup iframe at <profile>/settings/?gdc_set=1 (own profile only). The page is a standalone document
 * printed on template_redirect, so no theme / Youzify chrome loads. It reuses what already exists:
 *   - photo and cover: BuddyPress' own admin-ajax handlers (bp_avatar_upload / bp_avatar_set / bp_avatar_delete,
 *     bp_cover_image_upload / bp_cover_image_delete) with their own nonces
 *   - profile fields: every xProfile field the admin set up in Member Management > Profile Fields
 *   - email notifications: whatever the components print on bp_notification_settings, read back as switches and saved
 *     to the same notification_* user meta
 *   - Privacy & data, Blocked members, Export data: the existing settings screens, framed without their sub-nav
 * Without this file the popup falls back to the plain BuddyPress settings screens.
 *
 * @package gend-society
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** True on the member's own settings URL carrying ?gdc_set. */
function gend_society_settings_ui_active() {
    return ! empty( $_GET['gdc_set'] ) && is_user_logged_in() && function_exists( 'bp_is_my_profile' ) && bp_is_my_profile()
        && function_exists( 'bp_is_settings_component' ) && bp_is_settings_component();
}

/** Frames of the existing settings screens drop their own tab strip. */
add_action( 'wp_head', function () {
    if ( empty( $_GET['gdc_set_frame'] ) ) return;
    echo '<style id="gdc-set-frame">' . gend_society_settings_frame_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
}, 99 );

/** CSS for the existing settings screens shown in the Privacy, Blocked and Export tabs (framed, no sub-nav). */
function gend_society_settings_frame_css() {
    return <<<'CSS'
#subnav,.item-list-tabs#subnav{display:none!important}
html{background:#080c14!important;color-scheme:dark}
html body.gdc-tab-only,html body.gdc-tab-only *{box-sizing:border-box}
html body.gdc-tab-only{background:transparent!important;min-height:100vh;width:100%!important;overflow-x:hidden;padding:22px 26px 40px!important;margin:0!important;color:#e8edf5!important;font:500 14px/1.55 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif!important;-webkit-font-smoothing:antialiased}
html body.gdc-tab-only #youzify,html body.gdc-tab-only #youzify-bp,html body.gdc-tab-only .youzify-content,html body.gdc-tab-only .youzify-page-main-content,html body.gdc-tab-only .youzify-right-sidebar-layout{padding:0!important;margin:0!important;max-width:none!important;background:transparent!important;border:0!important;box-shadow:none!important}
html body.gdc-tab-only .youzify-main-column{width:100%!important;max-width:860px!important;margin:0 auto!important;padding:22px!important;border:1px solid rgba(255,255,255,.09)!important;border-radius:20px!important;background:rgba(255,255,255,.035)!important;box-shadow:none!important}
html body.gdc-tab-only h1,html body.gdc-tab-only h2,html body.gdc-tab-only h3,html body.gdc-tab-only h4,html body.gdc-tab-only .bp-heading{margin:0 0 16px!important;padding:0!important;font:800 .7rem/1.3 Inter,system-ui,sans-serif!important;letter-spacing:1.6px!important;text-transform:uppercase!important;color:#8e9ab0!important}
html body.gdc-tab-only p,html body.gdc-tab-only li,html body.gdc-tab-only label,html body.gdc-tab-only small,html body.gdc-tab-only td,html body.gdc-tab-only th,html body.gdc-tab-only span{font-family:Inter,system-ui,sans-serif!important}
html body.gdc-tab-only p,html body.gdc-tab-only li{color:#cdd5e3!important}
html body.gdc-tab-only small{color:#8e9ab0!important}
html body.gdc-tab-only a{color:#38bdf8!important}
html body.gdc-tab-only ul{padding-left:20px;margin:0 0 16px}
html body.gdc-tab-only .gend-privacy{max-width:none!important;font-size:14px!important}
html body.gdc-tab-only .gend-privacy hr{border:0!important;border-top:1px solid rgba(255,255,255,.09)!important;margin:22px 0!important;opacity:1!important}
html body.gdc-tab-only .gend-privacy__note{background:rgba(34,197,94,.14)!important;border:1px solid rgba(34,197,94,.4)!important;color:#bbf7d0!important}
html body.gdc-tab-only .gend-privacy label{align-items:flex-start;gap:12px!important}
html body.gdc-tab-only input[type=checkbox]{-webkit-appearance:none!important;appearance:none!important;flex:0 0 22px;width:22px!important;height:22px!important;margin:2px 0 0!important;border-radius:7px!important;border:1.5px solid rgba(255,255,255,.28)!important;background:rgba(255,255,255,.05)!important;cursor:pointer;position:relative;transition:all .15s}
html body.gdc-tab-only input[type=checkbox]:checked{background:linear-gradient(135deg,#b608c9,#8b5cf6)!important;border-color:transparent!important}
html body.gdc-tab-only input[type=checkbox]:checked::after{content:"";position:absolute;left:7px;top:3px;width:5px;height:10px;border:solid #fff;border-width:0 2.2px 2.2px 0;transform:rotate(45deg)}
html body.gdc-tab-only .gend-privacy small{margin-left:0!important}
html body.gdc-tab-only .gend-privacy label+br+small{margin-left:34px!important}
html body.gdc-tab-only button,html body.gdc-tab-only .button,html body.gdc-tab-only input[type=submit],html body.gdc-tab-only a.button{display:inline-flex!important;align-items:center;gap:8px;padding:11px 20px!important;border-radius:999px!important;border:1px solid rgba(255,255,255,.18)!important;background:rgba(255,255,255,.06)!important;color:#e8edf5!important;font:800 .78rem/1 Inter,system-ui,sans-serif!important;letter-spacing:.5px!important;text-transform:none!important;cursor:pointer;box-shadow:none!important;transition:transform .15s,background .15s}
html body.gdc-tab-only button:hover,html body.gdc-tab-only .button:hover,html body.gdc-tab-only input[type=submit]:hover{transform:translateY(-1px);background:rgba(255,255,255,.1)!important}
html body.gdc-tab-only .button-primary,html body.gdc-tab-only button[name=op][value=save],html body.gdc-tab-only #bp-data-export button,html body.gdc-tab-only #bp-data-export input[type=submit]{border:0!important;background:linear-gradient(135deg,#b608c9,#8b5cf6)!important;color:#fff!important;box-shadow:0 12px 30px -12px rgba(182,8,201,.8)!important}
html body.gdc-tab-only button[value=delete]{color:#ffb4b4!important;border-color:rgba(255,107,107,.4)!important}
html body.gdc-tab-only button[value=delete]:hover{background:rgba(255,80,80,.22)!important}
html body.gdc-tab-only #bp-data-export button svg{display:none}
@media(max-width:760px){html body.gdc-tab-only{padding:14px 14px 30px!important}}
CSS;
}

/** Cover currently shown on the profile header (own upload, else the site default). */
// Member covers fill the profile header as a viewport-sized backdrop (background-size: cover, fixed). Youzify forces
// 1350x350 for every cover and BuddyPress crops wider uploads to that strip, which then blew up blurry and cropped.
// Members get a 16:9 cover; group covers keep Youzify's size.
add_filter( 'bp_attachments_get_cover_image_dimensions', function ( $wh, $settings = array(), $component = '' ) {
    return 'members' === $component ? array( 'width' => 1920, 'height' => 1080 ) : $wh;
}, 20, 3 );

function gend_society_settings_cover( $user_id ) {
    $custom = function_exists( 'bp_attachments_get_attachment' )
        ? (string) bp_attachments_get_attachment( 'url', array( 'object_dir' => 'members', 'item_id' => $user_id ) ) : '';
    $shown = $custom;
    if ( ! $shown && function_exists( 'gend_sn_option' ) ) $shown = (string) gend_sn_option( 'youzify_default_profiles_cover', '' );
    if ( ! $shown && function_exists( 'gend_sn_default_profile_cover' ) ) $shown = (string) gend_sn_default_profile_cover();
    return array( 'url' => $shown, 'custom' => '' !== $custom );
}

/** xProfile groups with the member's values: [ ['name' =>, 'fields' => [BP_XProfile_Field…]], … ]. */
function gend_society_settings_xprofile( $user_id ) {
    if ( ! function_exists( 'bp_xprofile_get_groups' ) ) return array();
    $out = array();
    $groups = bp_xprofile_get_groups( array( 'fetch_fields' => true, 'fetch_field_data' => true, 'user_id' => $user_id, 'hide_empty_groups' => true ) );
    foreach ( (array) $groups as $g ) {
        if ( empty( $g->fields ) ) continue;
        $out[] = array( 'name' => (string) $g->name, 'fields' => $g->fields );
    }
    return $out;
}

function gend_society_settings_field_value( $field ) {
    $v = isset( $field->data->value ) ? maybe_unserialize( $field->data->value ) : '';
    return is_array( $v ) ? array_map( 'strval', $v ) : (string) $v;
}

/** Field options (selectbox, radio, checkbox, multiselectbox): [name => name]. */
function gend_society_settings_field_options( $field ) {
    $o = array();
    foreach ( (array) $field->get_children() as $c ) $o[ (string) $c->name ] = (string) $c->name;
    return $o;
}

function gend_society_settings_render_field( $field ) {
    $id    = (int) $field->id;
    $type  = (string) $field->type;
    $val   = gend_society_settings_field_value( $field );
    $req   = ! empty( $field->is_required );
    $name  = 'f[' . $id . ']';
    $label = esc_html( $field->name ) . ( $req ? ' <i class="req" aria-hidden="true">*</i>' : '' );
    $wide  = in_array( $type, array( 'textarea', 'checkbox', 'radio', 'multiselectbox' ), true );
    echo '<div class="fld' . ( $wide ? ' fld--wide' : '' ) . '"><label class="lbl" for="f' . $id . '">' . $label . '</label>';
    switch ( $type ) {
        case 'textarea':
            echo '<textarea id="f' . $id . '" name="' . esc_attr( $name ) . '" rows="5"' . ( $req ? ' required' : '' ) . '>' . esc_textarea( is_array( $val ) ? '' : $val ) . '</textarea>';
            break;
        case 'selectbox':
            echo '<select id="f' . $id . '" name="' . esc_attr( $name ) . '"' . ( $req ? ' required' : '' ) . '><option value="">' . esc_html__( 'Select…', 'gend-society' ) . '</option>';
            foreach ( gend_society_settings_field_options( $field ) as $o ) echo '<option value="' . esc_attr( $o ) . '"' . selected( $val, $o, false ) . '>' . esc_html( $o ) . '</option>';
            echo '</select>';
            break;
        case 'multiselectbox':
        case 'checkbox':
            $have = (array) $val;
            echo '<div class="chips">';
            foreach ( gend_society_settings_field_options( $field ) as $o ) {
                echo '<label class="chip"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $o ) . '"' . checked( in_array( $o, $have, true ), true, false ) . '><span>' . esc_html( $o ) . '</span></label>';
            }
            echo '</div>';
            break;
        case 'radio':
            echo '<div class="chips">';
            foreach ( gend_society_settings_field_options( $field ) as $o ) {
                echo '<label class="chip"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $o ) . '"' . checked( $val, $o, false ) . '><span>' . esc_html( $o ) . '</span></label>';
            }
            echo '</div>';
            break;
        case 'datebox':
            $d = is_array( $val ) ? '' : substr( (string) $val, 0, 10 );
            echo '<input id="f' . $id . '" type="date" name="' . esc_attr( $name ) . '" value="' . esc_attr( $d ) . '"' . ( $req ? ' required' : '' ) . '>';
            break;
        default:
            $in = array( 'number' => 'number', 'url' => 'url', 'telephone' => 'tel' );
            $t  = isset( $in[ $type ] ) ? $in[ $type ] : 'text';
            echo '<input id="f' . $id . '" type="' . esc_attr( $t ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_array( $val ) ? '' : $val ) . '"' . ( $req ? ' required' : '' ) . ' autocomplete="off">';
    }
    if ( ! empty( $field->description ) ) echo '<small class="hint">' . esc_html( wp_strip_all_tags( $field->description ) ) . '</small>';
    echo '</div>';
}

/**
 * Email notification switches: whatever the components print on bp_notification_settings (radio pairs in tables),
 * read back as [ ['title' =>, 'rows' => [ ['key' =>, 'label' =>, 'on' => bool], … ]], … ].
 */
function gend_society_settings_notification_groups( $user_id ) {
    $bp  = function_exists( 'buddypress' ) ? buddypress() : null;
    $was = $bp && isset( $bp->displayed_user ) ? $bp->displayed_user : null;
    if ( $bp ) {
        if ( ! isset( $bp->displayed_user ) || ! is_object( $bp->displayed_user ) ) $bp->displayed_user = new stdClass();
        $bp->displayed_user->id = $user_id;
    }
    ob_start();
    do_action( 'bp_notification_settings' );
    $html = ob_get_clean();
    if ( $bp && null !== $was ) $bp->displayed_user = $was;
    $groups = array();
    if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) return $groups;
    $dom = new DOMDocument();
    libxml_use_internal_errors( true );
    $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
    libxml_clear_errors();
    $xp = new DOMXPath( $dom );
    foreach ( $xp->query( '//table[contains(concat(" ",normalize-space(@class)," ")," notification-settings ")]' ) as $t ) {
        $th    = $xp->query( './/th[contains(@class,"title")]', $t )->item( 0 );
        $title = $th ? trim( preg_replace( '/\s+/', ' ', $th->textContent ) ) : '';
        $rows  = array();
        foreach ( $xp->query( './/tbody/tr', $t ) as $tr ) {
            $yes = $xp->query( './/input[@type="radio"][@value="yes"]', $tr )->item( 0 );
            if ( ! $yes || ! preg_match( '/^notifications\[(notification_[A-Za-z0-9_\-]+)\]$/', $yes->getAttribute( 'name' ), $m ) ) continue;
            $no    = $xp->query( './/input[@type="radio"][@value="no"]', $tr )->item( 0 );
            $label = '';
            foreach ( $xp->query( './td', $tr ) as $td ) {
                if ( $xp->query( './/input', $td )->length ) continue;
                $txt = trim( preg_replace( '/\s+/', ' ', str_replace( "\xC2\xA0", ' ', $td->textContent ) ) );
                if ( '' !== $txt ) { $label = $txt; break; }
            }
            $rows[] = array(
                'key'   => $m[1],
                'label' => $label ? $label : $m[1],
                'on'    => $yes->hasAttribute( 'checked' ) || ! ( $no && $no->hasAttribute( 'checked' ) ),
            );
        }
        if ( $rows ) $groups[] = array( 'title' => $title, 'rows' => $rows );
    }
    return $groups;
}

/** ───────────────────────── page ───────────────────────── */
add_action( 'template_redirect', function () {
    if ( ! gend_society_settings_ui_active() ) return;
    $uid  = get_current_user_id();
    $user = wp_get_current_user();
    nocache_headers();
    header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
    header( 'X-Robots-Tag: noindex' );

    $avatar_url = function_exists( 'bp_core_fetch_avatar' ) ? bp_core_fetch_avatar( array( 'item_id' => $uid, 'object' => 'user', 'type' => 'full', 'html' => false ) ) : get_avatar_url( $uid );
    $has_avatar = function_exists( 'bp_get_user_has_avatar' ) ? (bool) bp_get_user_has_avatar( $uid ) : false;
    $cover      = gend_society_settings_cover( $uid );
    $groups     = gend_society_settings_xprofile( $uid );
    $notif      = gend_society_settings_notification_groups( $uid );
    $base       = trailingslashit( bp_displayed_user_domain() . bp_get_settings_slug() );
    $frame_qs   = array( 'gdc_tab_only' => '1', 'gdc_set_frame' => '1' );
    $cfg = array(
        'ajax'    => admin_url( 'admin-ajax.php' ),
        'uid'     => $uid,
        'nonce'   => wp_create_nonce( 'gdc_set' ),
        'upload'  => wp_create_nonce( 'bp-uploader' ),
        'crop'    => wp_create_nonce( 'bp_avatar_cropstore' ),
        'delAv'   => wp_create_nonce( 'bp_delete_avatar_link' ),
        'delCov'  => wp_create_nonce( 'bp_delete_cover_image' ),
        'frames'  => array(
            'privacy' => add_query_arg( $frame_qs, $base . 'privacy-data/' ),
            'blocked' => add_query_arg( $frame_qs, $base . 'blocked-members/' ),
            'export'  => add_query_arg( $frame_qs, $base . 'data/' ),
        ),
    );
    $tabs = array(
        'profile' => array( __( 'Profile', 'gend-society' ), '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>' ),
        'account' => array( __( 'Account', 'gend-society' ), '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>' ),
        'notify'  => array( __( 'Email notifications', 'gend-society' ), '<path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/>' ),
        'privacy' => array( __( 'Privacy & data', 'gend-society' ), '<path d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6z"/>' ),
        'blocked' => array( __( 'Blocked members', 'gend-society' ), '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>' ),
        'export'  => array( __( 'Export data', 'gend-society' ), '<path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/>' ),
    );
    ?><!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php esc_html_e( 'Profile settings', 'gend-society' ); ?></title>
<style><?php echo gend_society_settings_css(); // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
</head>
<body class="gset">
<div class="shell">
  <nav class="rail" role="tablist" aria-label="<?php esc_attr_e( 'Settings', 'gend-society' ); ?>">
    <?php foreach ( $tabs as $key => $t ) : ?>
    <button type="button" role="tab" class="tab" data-tab="<?php echo esc_attr( $key ); ?>" aria-selected="false">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $t[1]; // phpcs:ignore WordPress.Security.EscapeOutput ?></svg>
      <span><?php echo esc_html( $t[0] ); ?></span>
    </button>
    <?php endforeach; ?>
  </nav>
  <main class="stage">

    <section class="panel" data-panel="profile" hidden>
      <div class="hero">
        <div class="cover" id="cover" style="<?php echo $cover['url'] ? 'background-image:url(' . esc_url( $cover['url'] ) . ')' : ''; ?>">
          <div class="cover__shade"></div>
          <div class="cover__acts">
            <button type="button" class="btn btn--glass" id="cover-pick"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg><?php esc_html_e( 'Change cover', 'gend-society' ); ?></button>
            <button type="button" class="btn btn--glass btn--danger" id="cover-del"<?php echo $cover['custom'] ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'gend-society' ); ?></button>
          </div>
          <div class="busy" id="cover-busy" hidden><i></i></div>
        </div>
        <div class="who">
          <div class="av" id="av">
            <img id="av-img" src="<?php echo esc_url( $avatar_url ); ?>" alt="">
            <button type="button" class="av__pick" id="av-pick" aria-label="<?php esc_attr_e( 'Change profile photo', 'gend-society' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg></button>
            <div class="busy busy--round" id="av-busy" hidden><i></i></div>
          </div>
          <div class="who__txt">
            <strong id="who-name"><?php echo esc_html( $user->display_name ); ?></strong>
            <span><?php esc_html_e( 'You can drag and zoom to frame it after choosing. JPG, PNG or GIF.', 'gend-society' ); ?></span>
            <button type="button" class="lnk" id="av-del"<?php echo $has_avatar ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove photo', 'gend-society' ); ?></button>
          </div>
        </div>
        <input type="file" id="av-file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
        <input type="file" id="cover-file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
      </div>

      <form id="form-profile" class="card" autocomplete="off">
        <?php if ( ! $groups ) : ?>
          <p class="muted"><?php esc_html_e( 'No profile fields are set up yet.', 'gend-society' ); ?></p>
        <?php endif; ?>
        <?php foreach ( $groups as $gi => $g ) : ?>
          <?php if ( count( $groups ) > 1 || 'Base' !== $g['name'] ) : ?><h3 class="sub"><?php echo esc_html( $g['name'] ); ?></h3><?php else : ?><h3 class="sub"><?php esc_html_e( 'About you', 'gend-society' ); ?></h3><?php endif; ?>
          <div class="grid">
            <?php foreach ( $g['fields'] as $field ) gend_society_settings_render_field( $field ); ?>
          </div>
        <?php endforeach; ?>
        <div class="bar"><button type="submit" class="btn btn--primary"><?php esc_html_e( 'Save profile', 'gend-society' ); ?></button><span class="msg" role="status"></span></div>
      </form>
    </section>

    <section class="panel" data-panel="account" hidden>
      <form id="form-account" class="card" autocomplete="off">
        <h3 class="sub"><?php esc_html_e( 'Sign-in details', 'gend-society' ); ?></h3>
        <div class="grid">
          <div class="fld fld--wide"><label class="lbl" for="acc-email"><?php esc_html_e( 'Account email', 'gend-society' ); ?></label><input id="acc-email" type="email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>" required autocomplete="email"></div>
          <div class="fld"><label class="lbl" for="acc-new"><?php esc_html_e( 'New password', 'gend-society' ); ?></label><input id="acc-new" type="password" name="new" minlength="10" placeholder="<?php esc_attr_e( 'Leave blank to keep', 'gend-society' ); ?>" autocomplete="new-password"></div>
          <div class="fld"><label class="lbl" for="acc-new2"><?php esc_html_e( 'Repeat new password', 'gend-society' ); ?></label><input id="acc-new2" type="password" name="new2" autocomplete="new-password"></div>
          <div class="fld fld--wide fld--confirm"><label class="lbl" for="acc-cur"><?php esc_html_e( 'Current password', 'gend-society' ); ?> <i class="req">*</i></label><input id="acc-cur" type="password" name="current" required autocomplete="current-password"><small class="hint"><?php esc_html_e( 'Required to change your email or password. Passwords need at least 10 characters.', 'gend-society' ); ?></small></div>
        </div>
        <div class="bar"><button type="submit" class="btn btn--primary"><?php esc_html_e( 'Save changes', 'gend-society' ); ?></button><span class="msg" role="status"></span></div>
      </form>
    </section>

    <section class="panel" data-panel="notify" hidden>
      <form id="form-notify" class="card">
        <h3 class="sub"><?php esc_html_e( 'Send me an email when', 'gend-society' ); ?></h3>
        <?php if ( ! $notif ) : ?><p class="muted"><?php esc_html_e( 'There are no email notifications to manage.', 'gend-society' ); ?></p><?php endif; ?>
        <?php foreach ( $notif as $g ) : ?>
        <div class="ngrp">
          <div class="ngrp__head"><span><?php echo esc_html( $g['title'] ); ?></span><span class="ngrp__all"><button type="button" class="lnk" data-all="1"><?php esc_html_e( 'All on', 'gend-society' ); ?></button><button type="button" class="lnk" data-all="0"><?php esc_html_e( 'All off', 'gend-society' ); ?></button></span></div>
          <?php foreach ( $g['rows'] as $r ) : ?>
          <label class="nrow"><span class="nrow__t"><?php echo esc_html( $r['label'] ); ?></span><span class="sw"><input type="checkbox" name="n[<?php echo esc_attr( $r['key'] ); ?>]" value="1"<?php checked( $r['on'] ); ?>><i></i></span></label>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php if ( $notif ) : ?><div class="bar"><button type="submit" class="btn btn--primary"><?php esc_html_e( 'Save notification settings', 'gend-society' ); ?></button><span class="msg" role="status"></span></div><?php endif; ?>
      </form>
    </section>

    <?php foreach ( array( 'privacy', 'blocked', 'export' ) as $fk ) : ?>
    <section class="panel panel--frame" data-panel="<?php echo esc_attr( $fk ); ?>" hidden>
      <div class="frameload"><i></i></div>
      <iframe title="<?php echo esc_attr( $tabs[ $fk ][0] ); ?>" data-frame="<?php echo esc_attr( $fk ); ?>"></iframe>
    </section>
    <?php endforeach; ?>

  </main>
</div>
<div class="crop" id="crop" role="dialog" aria-modal="true" aria-labelledby="crop-title" hidden>
  <div class="crop__box">
    <div class="crop__head"><strong id="crop-title"></strong><span class="crop__hint" id="crop-hint"></span></div>
    <div class="crop__stage" id="crop-stage"><canvas id="crop-cv"></canvas><div class="crop__frame" aria-hidden="true"></div></div>
    <div class="crop__tools">
      <button type="button" class="crop__ic" id="crop-out" aria-label="<?php esc_attr_e( 'Zoom out', 'gend-society' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 12h14"/></svg></button>
      <input type="range" id="crop-zoom" min="1" max="5" step="0.01" value="1" aria-label="<?php esc_attr_e( 'Zoom', 'gend-society' ); ?>">
      <button type="button" class="crop__ic" id="crop-in" aria-label="<?php esc_attr_e( 'Zoom in', 'gend-society' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 12h14M12 5v14"/></svg></button>
      <button type="button" class="lnk crop__reset" id="crop-reset"><?php esc_html_e( 'Reset', 'gend-society' ); ?></button>
    </div>
    <div class="crop__bar">
      <button type="button" class="btn" id="crop-cancel"><?php esc_html_e( 'Cancel', 'gend-society' ); ?></button>
      <button type="button" class="btn btn--primary" id="crop-save"><?php esc_html_e( 'Save', 'gend-society' ); ?></button>
    </div>
  </div>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script>window.GSET = <?php echo wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP ); ?>;</script>
<script><?php echo gend_society_settings_js(); // phpcs:ignore WordPress.Security.EscapeOutput ?></script>
</body>
</html>
    <?php
    exit;
}, 1 );

/** ───────────────────────── AJAX ───────────────────────── */

function gend_society_settings_ajax_guard() {
    check_ajax_referer( 'gdc_set', 'nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( array( 'message' => __( 'Please sign in again.', 'gend-society' ) ), 401 );
    return get_current_user_id();
}

add_action( 'wp_ajax_gend_society_settings_profile', function () {
    $uid = gend_society_settings_ajax_guard();
    if ( ! function_exists( 'xprofile_set_field_data' ) ) wp_send_json_error( array( 'message' => __( 'Profile fields are unavailable.', 'gend-society' ) ) );
    $post  = isset( $_POST['f'] ) && is_array( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : array();
    $old   = array();
    $new   = array();
    $ids   = array();
    $plan  = array();
    foreach ( gend_society_settings_xprofile( $uid ) as $g ) {
        foreach ( $g['fields'] as $field ) {
            $id   = (int) $field->id;
            $type = (string) $field->type;
            $raw  = isset( $post[ $id ] ) ? $post[ $id ] : ( in_array( $type, array( 'checkbox', 'multiselectbox' ), true ) ? array() : '' );
            $opts = in_array( $type, array( 'selectbox', 'radio', 'checkbox', 'multiselectbox' ), true ) ? gend_society_settings_field_options( $field ) : array();
            switch ( $type ) {
                case 'textarea':       $val = sanitize_textarea_field( is_array( $raw ) ? '' : $raw ); break;
                case 'url':            $val = esc_url_raw( is_array( $raw ) ? '' : trim( $raw ) ); break;
                case 'number':         $val = is_array( $raw ) || '' === trim( $raw ) ? '' : ( is_numeric( $raw ) ? (string) $raw : '' ); break;
                case 'datebox':        $val = ( ! is_array( $raw ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) ? $raw . ' 00:00:00' : ''; break;
                case 'selectbox':
                case 'radio':          $val = ( ! is_array( $raw ) && isset( $opts[ $raw ] ) ) ? $raw : ''; break;
                case 'checkbox':
                case 'multiselectbox': $val = array_values( array_filter( array_map( 'strval', (array) $raw ), function ( $o ) use ( $opts ) { return isset( $opts[ $o ] ); } ) ); break;
                default:               $val = sanitize_text_field( is_array( $raw ) ? '' : $raw );
            }
            if ( ! empty( $field->is_required ) && ( '' === $val || array() === $val ) ) {
                wp_send_json_error( array( 'message' => sprintf( __( '%s is required.', 'gend-society' ), $field->name ) ) );
            }
            $plan[] = array( $id, $val, ! empty( $field->is_required ) );
            $old[ $id ] = array( 'value' => gend_society_settings_field_value( $field ) );
        }
    }
    foreach ( $plan as $p ) {
        xprofile_set_field_data( $p[0], $uid, $p[1], $p[2] );
        $ids[]         = $p[0];
        $new[ $p[0] ]  = array( 'value' => $p[1] );
    }
    do_action( 'xprofile_updated_profile', $uid, $ids, false, $old, $new );
    clean_user_cache( $uid );
    wp_send_json_success( array( 'name' => (string) get_userdata( $uid )->display_name ) );
} );

add_action( 'wp_ajax_gend_society_settings_account', function () {
    $uid  = gend_society_settings_ajax_guard();
    $user = get_userdata( $uid );
    $key  = 'gs_set_pw_' . $uid;
    $fail = (int) get_transient( $key );
    if ( $fail >= 5 ) wp_send_json_error( array( 'message' => __( 'Too many attempts. Try again in ten minutes.', 'gend-society' ) ), 429 );
    $cur = isset( $_POST['current'] ) ? (string) wp_unslash( $_POST['current'] ) : '';
    if ( '' === $cur || ! wp_check_password( $cur, $user->user_pass, $uid ) ) {
        set_transient( $key, $fail + 1, 10 * MINUTE_IN_SECONDS );
        wp_send_json_error( array( 'message' => __( 'Your current password is not right.', 'gend-society' ) ) );
    }
    delete_transient( $key );
    $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $new   = isset( $_POST['new'] ) ? (string) wp_unslash( $_POST['new'] ) : '';
    $new2  = isset( $_POST['new2'] ) ? (string) wp_unslash( $_POST['new2'] ) : '';
    $args  = array( 'ID' => $uid );
    $msgs  = array();
    if ( '' === $email || ! is_email( $email ) ) wp_send_json_error( array( 'message' => __( 'Enter a valid email address.', 'gend-society' ) ) );
    if ( strtolower( $email ) !== strtolower( $user->user_email ) ) {
        $owner = email_exists( $email );
        if ( $owner && (int) $owner !== $uid ) wp_send_json_error( array( 'message' => __( 'That email address is already in use.', 'gend-society' ) ) );
        $args['user_email'] = $email;
        $msgs[] = __( 'Email updated.', 'gend-society' );
    }
    if ( '' !== $new || '' !== $new2 ) {
        if ( $new !== $new2 ) wp_send_json_error( array( 'message' => __( 'The new passwords do not match.', 'gend-society' ) ) );
        if ( strlen( $new ) < 10 ) wp_send_json_error( array( 'message' => __( 'Use at least 10 characters for the new password.', 'gend-society' ) ) );
        $args['user_pass'] = $new;
        $msgs[] = __( 'Password updated.', 'gend-society' );
    }
    if ( count( $args ) > 1 ) {
        $r = wp_update_user( $args );
        if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
        if ( isset( $args['user_pass'] ) ) {
            // A new password ends every session, including this one; keep the member signed in here.
            wp_set_current_user( $uid );
            wp_set_auth_cookie( $uid, true );
        }
    } else {
        $msgs[] = __( 'Nothing to change.', 'gend-society' );
    }
    wp_send_json_success( array( 'message' => implode( ' ', $msgs ) ) );
} );

add_action( 'wp_ajax_gend_society_settings_notifications', function () {
    $uid     = gend_society_settings_ajax_guard();
    $groups  = gend_society_settings_notification_groups( $uid );
    $posted  = isset( $_POST['n'] ) && is_array( $_POST['n'] ) ? wp_unslash( $_POST['n'] ) : array();
    foreach ( $groups as $g ) {
        foreach ( $g['rows'] as $r ) {
            bp_update_user_meta( $uid, $r['key'], empty( $posted[ $r['key'] ] ) ? 'no' : 'yes' );
        }
    }
    do_action( 'bp_core_notification_settings_after_save' );
    wp_send_json_success( array( 'message' => __( 'Notification settings saved.', 'gend-society' ) ) );
} );

/** ───────────────────────── assets ───────────────────────── */

function gend_society_settings_css() {
    return <<<'CSS'
:root{--bg:#070b12;--panel:#0d1420;--card:rgba(255,255,255,.035);--line:rgba(255,255,255,.09);--line2:rgba(255,255,255,.16);--tx:#e8edf5;--mu:#8e9ab0;--mg:#b608c9;--mg2:#8b5cf6;--bl:#38bdf8;--ok:#22c55e;--er:#ff6b6b;color-scheme:dark}
*{box-sizing:border-box}
html,body{margin:0;height:100%}
body.gset{background:radial-gradient(1100px 520px at 85% -10%,rgba(182,8,201,.16),transparent 60%),radial-gradient(900px 480px at -10% 110%,rgba(56,189,248,.10),transparent 60%),var(--bg);color:var(--tx);font:500 14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased;overflow:hidden}
button,input,select,textarea{font:inherit;color:inherit}
.shell{display:grid;grid-template-columns:232px minmax(0,1fr);height:100%}
.rail{display:flex;flex-direction:column;gap:4px;padding:18px 12px;border-right:1px solid var(--line);background:rgba(255,255,255,.015);overflow:auto}
.tab{display:flex;align-items:center;gap:11px;width:100%;padding:11px 13px;border:1px solid transparent;border-radius:12px;background:none;color:var(--mu);font-weight:700;font-size:.82rem;letter-spacing:.2px;text-align:left;cursor:pointer;transition:background .18s,color .18s,border-color .18s}
.tab svg{flex:0 0 18px;width:18px;height:18px}
.tab:hover{color:var(--tx);background:rgba(255,255,255,.05)}
.tab[aria-selected=true]{color:#fff;background:linear-gradient(135deg,rgba(182,8,201,.30),rgba(139,92,246,.16));border-color:rgba(182,8,201,.45);box-shadow:0 8px 28px -14px rgba(182,8,201,.7)}
.tab[aria-selected=true] svg{color:#f0a6ff}
.stage{position:relative;min-width:0;height:100%;overflow:auto;scroll-behavior:smooth;padding:22px 26px 36px}
.panel{max-width:860px;margin:0 auto;animation:rise .35s cubic-bezier(.22,1,.36,1)}
.panel[hidden]{display:none}
@keyframes rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.panel--frame{max-width:none;height:100%;margin:-22px -26px -36px;position:relative}
.panel--frame iframe{width:100%;height:100%;border:0;background:transparent;opacity:0;transition:opacity .25s}
.panel--frame iframe.on{opacity:1}
.frameload{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;transition:opacity .25s}
.frameload.done{opacity:0}
.frameload i,.busy i{width:30px;height:30px;border-radius:50%;border:3px solid rgba(255,255,255,.14);border-top-color:var(--mg);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.hero{position:relative;margin-bottom:18px;border:1px solid var(--line);border-radius:20px;background:var(--panel);overflow:visible}
.cover{position:relative;height:190px;border-radius:20px 20px 0 0;background:linear-gradient(120deg,#1b1030,#0b1a33 55%,#102a44) center/cover no-repeat;overflow:hidden}
.cover__shade{position:absolute;inset:0;background:linear-gradient(180deg,rgba(7,11,18,.05),rgba(7,11,18,.62))}
.cover__acts{position:absolute;right:14px;top:14px;display:flex;gap:8px}
.who{display:flex;align-items:flex-end;gap:16px;padding:0 22px 18px;margin-top:-46px;position:relative}
.av{position:relative;flex:0 0 108px;width:108px;height:108px;border-radius:50%;padding:3px;background:linear-gradient(135deg,var(--mg),var(--bl));box-shadow:0 14px 40px -10px rgba(0,0,0,.8)}
.av img{display:block;width:100%;height:100%;border-radius:50%;object-fit:cover;background:#111827;border:3px solid var(--panel)}
.av__pick{position:absolute;right:-2px;bottom:2px;width:34px;height:34px;border-radius:50%;border:2px solid var(--panel);background:linear-gradient(135deg,var(--mg),var(--mg2));color:#fff;cursor:pointer;display:grid;place-items:center;transition:transform .15s}
.av__pick:hover{transform:scale(1.08)}
.av__pick svg{width:16px;height:16px}
.who__txt{display:flex;flex-direction:column;gap:2px;padding-bottom:6px;min-width:0}
.who__txt strong{font-size:1.25rem;font-weight:800;letter-spacing:.1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.who__txt span{color:var(--mu);font-size:.78rem}
.busy{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(7,11,18,.55);backdrop-filter:blur(3px);z-index:2}
.busy--round{border-radius:50%}
.busy[hidden]{display:none}
.card{border:1px solid var(--line);border-radius:20px;background:var(--card);padding:22px;backdrop-filter:blur(14px)}
.sub{margin:0 0 14px;font-size:.7rem;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;color:var(--mu)}
.sub:not(:first-child){margin-top:24px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 18px}
.fld{display:flex;flex-direction:column;gap:6px;min-width:0}
.fld--wide{grid-column:1/-1}
.lbl{font-size:.7rem;font-weight:800;letter-spacing:.9px;text-transform:uppercase;color:#aeb8cb}
.req{color:var(--mg);font-style:normal}
input[type=text],input[type=email],input[type=password],input[type=url],input[type=tel],input[type=number],input[type=date],select,textarea{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--tx);outline:none;transition:border-color .15s,box-shadow .15s,background .15s}
textarea{resize:vertical;min-height:110px;line-height:1.55}
select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none' stroke='%238e9ab0' stroke-width='2'%3E%3Cpath d='m1 1 5 5 5-5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:38px}
input:focus,select:focus,textarea:focus{border-color:rgba(182,8,201,.7);box-shadow:0 0 0 3px rgba(182,8,201,.18);background:rgba(255,255,255,.06)}
::placeholder{color:#5d6980}
.hint{color:var(--mu);font-size:.74rem;line-height:1.4}
.fld--confirm{padding-top:16px;margin-top:2px;border-top:1px dashed var(--line)}
.chips{display:flex;flex-wrap:wrap;gap:8px}
.chip{position:relative;cursor:pointer}
.chip input{position:absolute;opacity:0;inset:0}
.chip span{display:inline-block;padding:8px 14px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);font-weight:700;font-size:.8rem;transition:all .15s}
.chip input:checked+span{background:linear-gradient(135deg,rgba(182,8,201,.35),rgba(139,92,246,.2));border-color:rgba(182,8,201,.6);color:#fff}
.bar{display:flex;align-items:center;gap:14px;margin-top:22px}
.msg{font-size:.82rem;font-weight:700}
.msg.ok{color:var(--ok)}.msg.err{color:var(--er)}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:999px;border:1px solid var(--line2);background:rgba(255,255,255,.06);color:var(--tx);font-weight:800;font-size:.8rem;letter-spacing:.5px;cursor:pointer;transition:transform .15s,box-shadow .15s,background .15s,opacity .15s}
.btn svg{width:15px;height:15px}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.1)}
.btn[disabled]{opacity:.55;pointer-events:none}
.btn--primary{border:0;background:linear-gradient(135deg,var(--mg),var(--mg2));color:#fff;box-shadow:0 12px 30px -12px rgba(182,8,201,.8)}
.btn--primary:hover{background:linear-gradient(135deg,#c61ad9,#9b72f8);box-shadow:0 16px 36px -12px rgba(182,8,201,.9)}
.btn--glass{padding:8px 14px;background:rgba(7,11,18,.55);backdrop-filter:blur(10px);border-color:rgba(255,255,255,.22);font-size:.74rem}
.btn--danger:hover{background:rgba(255,80,80,.3);border-color:rgba(255,107,107,.6)}
.btn[hidden],.lnk[hidden]{display:none}
.lnk{align-self:flex-start;padding:0;border:0;background:none;color:var(--bl);font-weight:700;font-size:.76rem;cursor:pointer}
.lnk:hover{text-decoration:underline}
.muted{color:var(--mu)}
.ngrp{margin-bottom:14px;border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.02);overflow:hidden}
.ngrp__head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line);background:rgba(255,255,255,.03);font-size:.7rem;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:#c6cfe0}
.ngrp__all{display:flex;gap:14px}
.nrow{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:13px 16px;border-bottom:1px solid rgba(255,255,255,.05);cursor:pointer;transition:background .15s}
.nrow:last-child{border-bottom:0}
.nrow:hover{background:rgba(255,255,255,.03)}
.nrow__t{font-weight:600;color:#dbe2ee;line-height:1.4}
.sw{position:relative;flex:0 0 44px;width:44px;height:26px}
.sw input{position:absolute;opacity:0;inset:0;cursor:pointer;margin:0}
.sw i{position:absolute;inset:0;border-radius:999px;background:rgba(255,255,255,.13);transition:background .2s}
.sw i::after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.4);transition:transform .2s cubic-bezier(.34,1.56,.64,1)}
.sw input:checked+i{background:linear-gradient(135deg,var(--mg),var(--mg2))}
.sw input:checked+i::after{transform:translateX(18px)}
.sw input:focus-visible+i{box-shadow:0 0 0 3px rgba(182,8,201,.35)}
.toast{position:fixed;left:50%;bottom:22px;transform:translate(-50%,24px);opacity:0;pointer-events:none;padding:11px 18px;border-radius:999px;background:#111a2b;border:1px solid var(--line2);box-shadow:0 18px 50px rgba(0,0,0,.6);font-weight:700;font-size:.82rem;transition:all .25s cubic-bezier(.22,1,.36,1);z-index:9}
.toast.on{opacity:1;transform:translate(-50%,0)}
.toast.err{border-color:rgba(255,107,107,.6);color:#ffb4b4}
@media(max-width:760px){
 .shell{grid-template-columns:minmax(0,1fr);grid-template-rows:auto minmax(0,1fr)}
 .rail{flex-direction:row;gap:6px;padding:10px 12px;border-right:0;border-bottom:1px solid var(--line);overflow-x:auto;scrollbar-width:none}
 .rail::-webkit-scrollbar{display:none}
 .tab{flex:0 0 auto;width:auto;padding:9px 13px;border-radius:999px;border-color:var(--line);white-space:nowrap}
 .stage{padding:14px 14px 30px}
 .panel--frame{margin:-14px -14px -30px}
 .grid{grid-template-columns:minmax(0,1fr)}
 .cover{height:140px}
 .who{padding:0 16px 16px;margin-top:-38px;gap:12px}
 .av{flex-basis:88px;width:88px;height:88px}
 .card{padding:16px}
}
.crop{position:fixed;inset:0;z-index:8;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(3,6,12,.78);backdrop-filter:blur(8px)}
.crop[hidden]{display:none}
.crop__box{width:min(760px,100%);max-height:100%;overflow:auto;display:flex;flex-direction:column;gap:14px;padding:18px;border:1px solid var(--line2);border-radius:22px;background:var(--panel);box-shadow:0 40px 120px -20px rgba(0,0,0,.85);animation:rise .3s cubic-bezier(.22,1,.36,1)}
.crop__head{display:flex;flex-direction:column;gap:2px}
.crop__head strong{font-size:1.02rem;font-weight:800}
.crop__hint{color:var(--mu);font-size:.78rem}
.crop__stage{position:relative;width:100%;margin:0 auto;border-radius:16px;overflow:hidden;background:#05080e;cursor:grab;touch-action:none;user-select:none}
.crop__stage.is-drag{cursor:grabbing}
.crop__stage canvas{display:block;width:100%;height:100%}
.crop__frame{position:absolute;inset:0;pointer-events:none;border:1px solid rgba(255,255,255,.35);border-radius:16px;background:linear-gradient(rgba(255,255,255,.14),rgba(255,255,255,.14)) 33.33% 0/1px 100% no-repeat,linear-gradient(rgba(255,255,255,.14),rgba(255,255,255,.14)) 66.66% 0/1px 100% no-repeat,linear-gradient(rgba(255,255,255,.14),rgba(255,255,255,.14)) 0 33.33%/100% 1px no-repeat,linear-gradient(rgba(255,255,255,.14),rgba(255,255,255,.14)) 0 66.66%/100% 1px no-repeat;opacity:0;transition:opacity .2s}
.crop__stage.is-drag .crop__frame,.crop__stage:hover .crop__frame{opacity:1}
.crop--round .crop__stage{max-width:420px}
.crop--round .crop__frame{border:0;border-radius:50%;box-shadow:0 0 0 999px rgba(3,6,12,.62),inset 0 0 0 2px rgba(255,255,255,.55);opacity:1}
.crop__tools{display:flex;align-items:center;gap:12px}
.crop__ic{flex:0 0 36px;width:36px;height:36px;border-radius:50%;border:1px solid var(--line2);background:rgba(255,255,255,.05);color:var(--tx);display:grid;place-items:center;cursor:pointer;transition:background .15s}
.crop__ic:hover{background:rgba(255,255,255,.12)}
.crop__ic svg{width:16px;height:16px}
.crop__tools input[type=range]{flex:1;min-width:0;accent-color:var(--mg);height:4px;cursor:pointer}
.crop__reset{align-self:center;margin-left:4px}
.crop__bar{display:flex;justify-content:flex-end;gap:10px}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
CSS;
}

function gend_society_settings_js() {
    return <<<'JS'
(function () {
  var C = window.GSET, $ = function (s, r) { return (r || document).querySelector(s); }, $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
  var changed = false;
  function tell() { changed = true; try { parent.postMessage({ gdcSettings: 'changed' }, location.origin); } catch (e) {} }
  var tt;
  function toast(m, err) { var t = $('#toast'); t.textContent = m; t.className = 'toast on' + (err ? ' err' : ''); clearTimeout(tt); tt = setTimeout(function () { t.className = 'toast'; }, 3200); }
  function post(fd) { return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function msgOf(j, d) { return (j && j.data && (j.data.message || (j.data.feedback && j.data.feedback.message))) || d; }

  /* tabs */
  var tabs = $$('.tab'), panels = $$('.panel');
  function show(k) {
    if (!$('.panel[data-panel="' + k + '"]')) k = 'profile';
    tabs.forEach(function (t) { t.setAttribute('aria-selected', t.dataset.tab === k ? 'true' : 'false'); });
    panels.forEach(function (p) { p.hidden = p.dataset.panel !== k; });
    $('.stage').scrollTop = 0;
    var f = $('.panel[data-panel="' + k + '"] iframe');
    if (f && !f.src) {
      var ld = $('.frameload', f.parentNode);
      f.addEventListener('load', function () { f.classList.add('on'); ld.classList.add('done'); });
      f.src = C.frames[f.dataset.frame];
    }
    try { history.replaceState(null, '', '#' + k); } catch (e) {}
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.tab); }); });
  show((location.hash || '').slice(1) || 'profile');

  /* forms */
  function bind(form, action, extra, done) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('button[type=submit]', form), out = $('.msg', form);
      var fd = new FormData(form);
      fd.append('action', action); fd.append('nonce', C.nonce);
      if (extra) extra(fd);
      btn.disabled = true; out.className = 'msg'; out.textContent = '';
      post(fd).then(function (j) {
        btn.disabled = false;
        if (j && j.success) { out.className = 'msg ok'; out.textContent = msgOf(j, 'Saved.'); toast(msgOf(j, 'Saved.')); if (done) done(j); }
        else { out.className = 'msg err'; out.textContent = msgOf(j, 'Could not save.'); toast(msgOf(j, 'Could not save.'), true); }
      }).catch(function () { btn.disabled = false; out.className = 'msg err'; out.textContent = 'Network error.'; toast('Network error.', true); });
    });
  }
  bind($('#form-profile'), 'gend_society_settings_profile', null, function (j) { if (j.data && j.data.name) $('#who-name').textContent = j.data.name; tell(); });
  bind($('#form-account'), 'gend_society_settings_account', null, function () { $('#acc-cur').value = ''; $('#acc-new').value = ''; $('#acc-new2').value = ''; });
  var nf = $('#form-notify');
  if (nf) {
    bind(nf, 'gend_society_settings_notifications');
    $$('[data-all]', nf).forEach(function (b) { b.addEventListener('click', function () { var on = b.dataset.all === '1'; $$('input[type=checkbox]', b.closest('.ngrp')).forEach(function (i) { i.checked = on; }); }); });
  }

  /* photo + cover (BuddyPress' own handlers) */
  function load(file) { return new Promise(function (res, rej) { var im = new Image(), u = URL.createObjectURL(file); im.onload = function () { URL.revokeObjectURL(u); res(im); }; im.onerror = function () { rej(new Error('That file is not a readable image.')); }; im.src = u; }); }
  function blobOf(canvas) { return new Promise(function (res, rej) { canvas.toBlob(function (b) { b ? res(b) : rej(new Error('Could not prepare the image.')); }, 'image/jpeg', .9); }); }

  /* crop editor: drag to reposition, wheel / pinch / slider to zoom; resolves the cropped JPEG blob, or null on cancel */
  var CR = $('#crop'), stage = $('#crop-stage'), cv = $('#crop-cv'), zoomIn = $('#crop-zoom');
  function cropper(im, o) {
    return new Promise(function (resolve) {
      var W = 0, H = 0, base = 1, z = 1, cx = 0, cy = 0, ptrs = {}, pinch = 0, last = null;
      $('#crop-title').textContent = o.title; $('#crop-hint').textContent = o.hint;
      CR.classList.toggle('crop--round', !!o.round);
      stage.style.aspectRatio = o.w + ' / ' + o.h;
      zoomIn.value = 1; CR.hidden = false;
      function clamp() {
        var sw = im.width * base * z, sh = im.height * base * z;
        cx = Math.min(sw / 2, Math.max(W - sw / 2, cx)); cy = Math.min(sh / 2, Math.max(H - sh / 2, cy));
      }
      function draw() {
        var d = window.devicePixelRatio || 1, x = cv.getContext('2d'), sw = im.width * base * z, sh = im.height * base * z, tw = Math.round(W * d), th = Math.round(H * d);
        if (cv.width !== tw || cv.height !== th) { cv.width = tw; cv.height = th; }
        x.setTransform(d, 0, 0, d, 0, 0); x.imageSmoothingQuality = 'high'; x.clearRect(0, 0, W, H);
        x.drawImage(im, cx - sw / 2, cy - sh / 2, sw, sh);
      }
      function size() {
        var r = stage.getBoundingClientRect(), ow = W;
        W = r.width; H = r.height; base = Math.max(W / im.width, H / im.height);
        if (ow) { cx *= W / ow; cy *= W / ow; } else { cx = W / 2; cy = H / 2; }
        clamp(); draw();
      }
      function zoomTo(nz, px, py) {
        nz = Math.min(5, Math.max(1, nz));
        if (px == null) { px = W / 2; py = H / 2; }
        cx = px - (px - cx) * nz / z; cy = py - (py - cy) * nz / z; z = nz;
        zoomIn.value = z; clamp(); draw();
      }
      function pt(e) { var r = stage.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
      function dist() { var k = Object.keys(ptrs); return k.length < 2 ? 0 : Math.hypot(ptrs[k[0]].x - ptrs[k[1]].x, ptrs[k[0]].y - ptrs[k[1]].y); }
      function down(e) { stage.setPointerCapture(e.pointerId); ptrs[e.pointerId] = pt(e); last = pt(e); pinch = dist(); stage.classList.add('is-drag'); }
      function move(e) {
        if (!ptrs[e.pointerId]) return;
        ptrs[e.pointerId] = pt(e);
        if (Object.keys(ptrs).length > 1) { var d = dist(); if (pinch) zoomTo(z * d / pinch); pinch = d; return; }
        var p = pt(e); cx += p.x - last.x; cy += p.y - last.y; last = p; clamp(); draw();
      }
      function up(e) { delete ptrs[e.pointerId]; pinch = dist(); var k = Object.keys(ptrs); last = k.length ? ptrs[k[0]] : null; if (!k.length) stage.classList.remove('is-drag'); }
      function wheel(e) { e.preventDefault(); var p = pt(e); zoomTo(z * Math.exp(-e.deltaY * 0.0015), p.x, p.y); }
      function onZoom() { zoomTo(+zoomIn.value); }
      function key(e) { if (e.key === 'Escape') finish(null); }
      var bound = [[stage, 'pointerdown', down], [stage, 'pointermove', move], [stage, 'pointerup', up], [stage, 'pointercancel', up], [stage, 'wheel', wheel, { passive: false }],
        [zoomIn, 'input', onZoom], [$('#crop-in'), 'click', function () { zoomTo(z * 1.2); }], [$('#crop-out'), 'click', function () { zoomTo(z / 1.2); }],
        [$('#crop-reset'), 'click', function () { z = 1; zoomIn.value = 1; cx = W / 2; cy = H / 2; clamp(); draw(); }],
        [$('#crop-cancel'), 'click', function () { finish(null); }], [$('#crop-save'), 'click', save], [window, 'resize', size], [document, 'keydown', key]];
      bound.forEach(function (b) { b[0].addEventListener(b[1], b[2], b[3]); });
      function finish(v) { bound.forEach(function (b) { b[0].removeEventListener(b[1], b[2], b[3]); }); CR.hidden = true; resolve(v); }
      function save() {
        var c = document.createElement('canvas'), k = o.w / W, sw = im.width * base * z, sh = im.height * base * z;
        c.width = o.w; c.height = o.h;
        var x = c.getContext('2d'); x.imageSmoothingQuality = 'high'; x.fillStyle = '#0b0e14'; x.fillRect(0, 0, o.w, o.h);
        x.drawImage(im, (cx - sw / 2) * k, (cy - sh / 2) * k, sw * k, sh * k);
        blobOf(c).then(finish, function (e) { toast(e.message, true); });
      }
      requestAnimationFrame(size);
    });
  }
  function params(fd) { fd.append('bp_params[object]', 'user'); fd.append('bp_params[item_id]', C.uid); fd.append('_wpnonce', C.upload); }
  function bust(u) { return u + (u.indexOf('?') < 0 ? '?' : '&') + 'v=' + Date.now(); }

  var avBusy = $('#av-busy'), covBusy = $('#cover-busy');
  $('#av-pick').addEventListener('click', function () { $('#av-file').click(); });
  $('#cover-pick').addEventListener('click', function () { $('#cover-file').click(); });

  $('#av-file').addEventListener('change', function () {
    var f = this.files[0]; this.value = ''; if (!f) return;
    load(f).then(function (im) {
      return cropper(im, { w: 640, h: 640, round: true, title: 'Position your profile photo', hint: 'Drag to move it, scroll or use the slider to zoom.' });
    }).then(function (blob) {
      if (!blob) return;
      avBusy.hidden = false;
      return av(blob);
    }).catch(function (e) { avBusy.hidden = true; toast(e.message || 'Upload failed.', true); });
  });
  function av(blob) {
    return Promise.resolve().then(function () {
      var fd = new FormData(); fd.append('action', 'bp_avatar_upload'); params(fd); fd.append('file', blob, 'avatar.jpg');
      return post(fd);
    }).then(function (j) {
      if (!j || !j.success) throw new Error(msgOf(j, 'Upload failed.'));
      var d = j.data, fd = new FormData();
      fd.append('action', 'bp_avatar_set'); fd.append('nonce', C.crop); fd.append('object', 'user'); fd.append('item_id', C.uid);
      fd.append('type', 'crop'); fd.append('original_file', d.url);
      fd.append('crop_x', 0); fd.append('crop_y', 0); fd.append('crop_w', Math.min(d.width, d.height)); fd.append('crop_h', Math.min(d.width, d.height));
      return post(fd);
    }).then(function (j) {
      if (!j || !j.success) throw new Error('Could not save the photo.');
      $('#av-img').src = bust(j.data.avatar); $('#av-del').hidden = false; toast('Profile photo updated.'); tell();
    }).catch(function (e) { toast(e.message || 'Upload failed.', true); }).then(function () { avBusy.hidden = true; });
  }
  $('#av-del').addEventListener('click', function () {
    avBusy.hidden = false;
    var fd = new FormData(); fd.append('action', 'bp_avatar_delete'); fd.append('nonce', C.delAv); fd.append('object', 'user'); fd.append('item_id', C.uid);
    post(fd).then(function (j) {
      if (!j || !j.success) throw new Error('Could not remove the photo.');
      $('#av-img').src = bust(j.data.avatar); $('#av-del').hidden = true; toast('Profile photo removed.'); tell();
    }).catch(function (e) { toast(e.message, true); }).then(function () { avBusy.hidden = true; });
  });

  $('#cover-file').addEventListener('change', function () {
    var f = this.files[0]; this.value = ''; if (!f) return;
    load(f).then(function (im) {
      return cropper(im, { w: 1920, h: 1080, title: 'Position your cover', hint: 'It fills the background behind your profile header. Drag to move it, scroll or use the slider to zoom.' });
    }).then(function (blob) {
      if (!blob) return;
      covBusy.hidden = false;
      var fd = new FormData(); fd.append('action', 'bp_cover_image_upload'); params(fd); fd.append('file', blob, 'cover.jpg');
      return post(fd).then(cov);
    }).catch(function (e) { toast(e.message || 'Upload failed.', true); }).then(function () { covBusy.hidden = true; });
  });
  function cov(j) {
    return Promise.resolve(j).then(function (j) {
      if (!j || !j.success) throw new Error(msgOf(j, 'Upload failed.'));
      $('#cover').style.backgroundImage = 'url("' + bust(j.data.url) + '")'; $('#cover-del').hidden = false; toast('Cover image updated.'); tell();
    });
  }
  $('#cover-del').addEventListener('click', function () {
    covBusy.hidden = false;
    var fd = new FormData(); fd.append('action', 'bp_cover_image_delete'); fd.append('nonce', C.delCov); fd.append('object', 'user'); fd.append('item_id', C.uid);
    post(fd).then(function (j) {
      if (!j || !j.success) throw new Error('Could not remove the cover.');
      $('#cover').style.backgroundImage = j.data && j.data.reset_url ? 'url("' + j.data.reset_url + '")' : ''; $('#cover-del').hidden = true; toast('Cover image removed.'); tell();
    }).catch(function (e) { toast(e.message, true); }).then(function () { covBusy.hidden = true; });
  });
})();
JS;
}
