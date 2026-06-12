<?php if (!defined('ABSPATH')) {
    exit;
}

// Ensure the current user has permission to manage users
if (!current_user_can('list_users')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'gend-society'));
}

// Handle form submission to save feature access
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gs_feature_access_nonce']) && wp_verify_nonce($_POST['gs_feature_access_nonce'], 'gs_save_feature_access')) {
    $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
    
    if ($target_user_id && current_user_can('edit_user', $target_user_id)) {
        // Sanitize and save the array of allowed menu slugs
        $allowed_slugs = isset($_POST['gs_allowed_menus']) && is_array($_POST['gs_allowed_menus']) ? array_map('sanitize_text_field', wp_unslash($_POST['gs_allowed_menus'])) : [];
        update_user_meta($target_user_id, 'gs_feature_access', $allowed_slugs);
        
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Feature access updated successfully.', 'gend-society') . '</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('You do not have permission to edit this user.', 'gend-society') . '</p></div>';
    }
}

// Fetch all users that can access the backend (at least edit_posts, adjust as needed)
$args = array(
    'role__not_in' => array('subscriber', 'customer'), // Exclude purely frontend roles if desired, or allow all depending on use case.
    'orderby'      => 'display_name',
    'order'        => 'ASC',
);
$users = get_users($args);

// gs_render_menu_access_checkboxes() now lives in inc/admin-menu.php so
// the AJAX modal handlers (wp_ajax_gs_feature_access_form / _save) can
// reuse it without requiring this page file's side effects.
$gs_feature_access_modal_nonce = wp_create_nonce('gs_feature_access_modal');
?>

<div class="gs-page wrap">
    <div class="gs-page-header">
        <h1 class="gs-page-title"><span class="gs-gradient-text">
                <?php esc_html_e('Feature Access', 'gend-society'); ?>
            </span></h1>
        <p><?php esc_html_e('Manage which menu items and features are available to each user on the backend and frontend admin bar.', 'gend-society'); ?></p>
    </div>

    <?php 
    // Determine if we are editing a specific user or listing all users
    $edit_user_id = isset($_GET['edit_user']) ? intval($_GET['edit_user']) : 0;
    
    if ($edit_user_id && current_user_can('edit_user', $edit_user_id)) {
        $edit_user_obj = get_userdata($edit_user_id);
        if ($edit_user_obj) {
            ?>
            <div class="gs-card">
                <div class="gs-card-header">
                    <h3><?php printf(esc_html__('Editing Access for: %s', 'gend-society'), esc_html($edit_user_obj->display_name)); ?></h3>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=gs-feature-access')); ?>" class="button"><?php esc_html_e('&larr; Back to List', 'gend-society'); ?></a>
                </div>
                <div class="gs-card-body">
                    <form method="post" action="">
                        <?php wp_nonce_field('gs_save_feature_access', 'gs_feature_access_nonce'); ?>
                        <input type="hidden" name="target_user_id" value="<?php echo esc_attr($edit_user_id); ?>">
                        
                        <?php echo gs_render_menu_access_checkboxes($edit_user_id); ?>
                        
                        <p class="submit">
                            <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e('Save Feature Access', 'gend-society'); ?>">
                        </p>
                    </form>
                </div>
            </div>
            <?php
        } else {
             echo '<div class="notice notice-error"><p>' . esc_html__('User not found.', 'gend-society') . '</p></div>';
        }
    } else {
    ?>
    
    <div class="gs-card">
        <div class="gs-card-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <h3 style="margin:0;"><?php esc_html_e('User List', 'gend-society'); ?></h3>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <?php if ( current_user_can( 'create_users' ) || current_user_can( 'promote_users' ) ) : ?>
                    <button type="button" class="button button-primary" id="gs-fa-invite-open" style="padding:7px 16px;">
                        <span class="dashicons dashicons-email-alt" style="vertical-align:middle; font-size:16px; width:16px; height:16px; margin-right:4px;"></span>
                        <?php esc_html_e('Invite New User', 'gend-society'); ?>
                    </button>
                <?php endif; ?>
                <input type="search" id="gs-fa-search" placeholder="<?php esc_attr_e('Search by name, username, or email…', 'gend-society'); ?>" autocomplete="off" style="min-width:240px; padding:8px 12px; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#fff;">
            </div>
        </div>
        <div class="gs-card-body">
            <style>
                /* User Access table — readability pass. WP's default list
                   table styles assume a white admin background; on the
                   glass surface they read as low-contrast grey. Lift the
                   colors, add row padding, and give each column its own
                   typographic role so the eye can scan quickly. */
                #gs-fa-users-table { background: transparent !important; border: 0 !important; box-shadow: none !important; }
                #gs-fa-users-table thead th {
                    background: transparent !important;
                    color: #cbd5f5 !important;
                    font-size: 0.72rem;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.08em;
                    padding: 14px 16px;
                    border-bottom: 1px solid rgba(255,255,255,0.14) !important;
                }
                #gs-fa-users-table tbody tr {
                    background: transparent !important;
                    transition: background 0.15s ease;
                }
                #gs-fa-users-table tbody tr + tr td { border-top: 1px solid rgba(255,255,255,0.05); }
                #gs-fa-users-table tbody tr:hover { background: rgba(78,170,255,0.06) !important; }
                #gs-fa-users-table tbody td {
                    padding: 14px 16px;
                    vertical-align: middle;
                    color: #e6edf7;
                    font-size: 0.92rem;
                    line-height: 1.45;
                }
                /* Avatar — slightly larger, soft ring, lifts the row */
                #gs-fa-users-table .gs-fa-avatar {
                    width: 38px; height: 38px;
                    border-radius: 50%;
                    object-fit: cover;
                    flex-shrink: 0;
                    border: 2px solid rgba(255,255,255,0.10);
                    box-shadow: 0 4px 12px rgba(0,0,0,0.35);
                    background: rgba(0,0,0,0.25);
                }
                /* Username button — looks like a real link, not <ins>-style underline */
                #gs-fa-users-table .gs-fa-open-modal[data-user-id] {
                    background: none; border: 0; padding: 0;
                    color: #8ab4f8;
                    font: inherit;
                    font-weight: 600;
                    cursor: pointer;
                    text-decoration: none;
                    transition: color 0.15s ease;
                }
                #gs-fa-users-table .gs-fa-open-modal[data-user-id]:hover { color: #fff; }
                /* Display name — brighter primary */
                #gs-fa-users-table td.column-name { color: #fff; font-weight: 500; }
                /* Email — monospace + subtle accent so it scans as data */
                #gs-fa-users-table td.column-email a {
                    color: #a5b4fc;
                    text-decoration: none;
                    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                    font-size: 0.86rem;
                    word-break: break-all;
                }
                #gs-fa-users-table td.column-email a:hover { color: #c7d2fe; text-decoration: underline; }
                /* Action button — refresh the WP "button button-small" feel */
                #gs-fa-users-table .gs-fa-open-modal.button {
                    background: rgba(78,170,255,0.12) !important;
                    border: 1px solid rgba(78,170,255,0.35) !important;
                    color: #8ab4f8 !important;
                    border-radius: 8px;
                    padding: 6px 14px;
                    font-weight: 600;
                    box-shadow: none !important;
                    text-shadow: none !important;
                    transition: all 0.15s ease;
                }
                #gs-fa-users-table .gs-fa-open-modal.button:hover {
                    background: rgba(78,170,255,0.22) !important;
                    color: #fff !important;
                    border-color: rgba(78,170,255,0.55) !important;
                }
            </style>
            <table class="wp-list-table widefat fixed striped users" id="gs-fa-users-table">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column column-username"><?php esc_html_e('Username', 'gend-society'); ?></th>
                        <th scope="col" class="manage-column column-name"><?php esc_html_e('Name', 'gend-society'); ?></th>
                        <th scope="col" class="manage-column column-email"><?php esc_html_e('Email', 'gend-society'); ?></th>
                        <th scope="col" class="manage-column column-action"><?php esc_html_e('Action', 'gend-society'); ?></th>
                    </tr>
                </thead>
                <tbody id="the-list">
                    <?php foreach ($users as $user) :
                        // Stash all searchable fields as lowercase data-search so JS filter is one string compare.
                        $search_blob = strtolower($user->user_login . ' ' . $user->display_name . ' ' . $user->user_email);
                        // Prefer the BuddyPress / Youzify avatar (handles uploaded
                        // custom avatars under wp-content/uploads/avatars/{id}/);
                        // falls back to Gravatar via get_avatar_url() when BP
                        // isn't loaded or the user has no Youzify upload.
                        if ( function_exists( 'bp_core_fetch_avatar' ) ) {
                            $gs_fa_avatar_url = bp_core_fetch_avatar( array(
                                'item_id' => $user->ID,
                                'object'  => 'user',
                                'type'    => 'thumb',
                                'html'    => false,
                            ) );
                        } else {
                            $gs_fa_avatar_url = get_avatar_url( $user->ID, array( 'size' => 64 ) );
                        }
                    ?>
                        <tr id="user-<?php echo esc_attr($user->ID); ?>" data-search="<?php echo esc_attr($search_blob); ?>">
                            <td class="username column-username" data-colname="<?php esc_attr_e('Username', 'gend-society'); ?>">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <img class="gs-fa-avatar" src="<?php echo esc_url( $gs_fa_avatar_url ); ?>" alt="" width="38" height="38" loading="lazy">
                                    <button type="button" class="gs-fa-open-modal" data-user-id="<?php echo esc_attr($user->ID); ?>"><?php echo esc_html($user->user_login); ?></button>
                                </div>
                            </td>
                            <td class="name column-name" data-colname="<?php esc_attr_e('Name', 'gend-society'); ?>">
                                <?php echo esc_html($user->display_name); ?>
                            </td>
                            <td class="email column-email" data-colname="<?php esc_attr_e('Email', 'gend-society'); ?>">
                                <a href="mailto:<?php echo esc_attr($user->user_email); ?>"><?php echo esc_html($user->user_email); ?></a>
                            </td>
                            <td class="action column-action" data-colname="<?php esc_attr_e('Action', 'gend-society'); ?>">
                                <button type="button" class="button button-small gs-fa-open-modal" data-user-id="<?php echo esc_attr($user->ID); ?>"><?php esc_html_e('Manage Access', 'gend-society'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)) : ?>
                        <tr><td colspan="4"><?php esc_html_e('No eligible users found.', 'gend-society'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p id="gs-fa-empty" style="display:none; color:var(--gs-muted, #94a3b8); padding:12px 0; font-style:italic;">
                <?php esc_html_e('No users match your search.', 'gend-society'); ?>
            </p>
        </div>
    </div>

    <!-- Manage Access modal (portaled to <body> on first open) -->
    <div class="gs-fa-modal" id="gs-fa-modal" hidden aria-hidden="true">
        <div class="gs-fa-modal__overlay" data-gs-fa-dismiss></div>
        <div class="gs-fa-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gs-fa-modal-title">
            <header class="gs-fa-modal__header">
                <h3 id="gs-fa-modal-title"><?php esc_html_e('Manage Access', 'gend-society'); ?></h3>
                <button type="button" class="gs-fa-modal__close" data-gs-fa-dismiss aria-label="<?php esc_attr_e('Close', 'gend-society'); ?>">&times;</button>
            </header>
            <div class="gs-fa-modal__body">
                <p class="gs-fa-modal__user" id="gs-fa-modal-user" style="margin:0 0 16px 0; color:var(--gs-muted, #94a3b8);"></p>
                <div id="gs-fa-modal-feedback" style="margin-bottom:12px;"></div>
                <form id="gs-fa-modal-form">
                    <input type="hidden" name="user_id" id="gs-fa-modal-user-id" value="">
                    <div id="gs-fa-modal-checkboxes"><em><?php esc_html_e('Loading…', 'gend-society'); ?></em></div>
                </form>
            </div>
            <footer class="gs-fa-modal__footer">
                <button type="button" class="button" data-gs-fa-dismiss><?php esc_html_e('Cancel', 'gend-society'); ?></button>
                <button type="button" class="button button-primary" id="gs-fa-modal-save"><?php esc_html_e('Save Feature Access', 'gend-society'); ?></button>
            </footer>
        </div>
    </div>

    <!-- Invite New User modal — embeds gs_invite_render_panel() from
         inc/profile-invite.php (email/CSV/Google Contacts + affiliate-tracked
         template editor with TinyMCE inbox integration). -->
    <div class="gs-fa-modal" id="gs-fa-invite-modal" hidden aria-hidden="true">
        <div class="gs-fa-modal__overlay" data-gs-invite-dismiss></div>
        <div class="gs-fa-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gs-fa-invite-title" style="max-width: 1100px;">
            <header class="gs-fa-modal__header">
                <h3 id="gs-fa-invite-title"><?php esc_html_e( 'Invite New Users', 'gend-society' ); ?></h3>
                <button type="button" class="gs-fa-modal__close" data-gs-invite-dismiss aria-label="<?php esc_attr_e( 'Close', 'gend-society' ); ?>">&times;</button>
            </header>
            <div class="gs-fa-modal__body" id="gs-fa-invite-body">
                <?php
                if ( function_exists( 'gs_invite_render_panel' ) ) {
                    gs_invite_render_panel();
                } else {
                    echo '<p style="color:#fca5a5;">' . esc_html__( 'Invite module not available on this install.', 'gend-society' ) . '</p>';
                }
                ?>
            </div>
        </div>
    </div>

    <style>
        .gs-fa-modal { display:none; position:fixed; top:0; left:0; right:0; bottom:0; inset:0; z-index:100000; align-items:center; justify-content:center; padding:24px; box-sizing:border-box; }
        .gs-fa-modal.is-open { display:flex !important; }
        .gs-fa-modal[hidden] { display:none !important; }
        .gs-fa-modal.is-open[hidden] { display:flex !important; }
        .gs-fa-modal__overlay { position:absolute; inset:0; background:rgba(5,7,10,0.88); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); }
        .gs-fa-modal__dialog { position:relative; background:#0f1217; border:1px solid #2a2f3a; border-radius:14px; width:100%; max-width:920px; max-height:calc(100vh - 48px); display:flex; flex-direction:column; box-shadow:0 30px 60px rgba(0,0,0,0.6); z-index:1; color:#e6edf7; }
        .gs-fa-modal__header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1px solid rgba(255,255,255,0.06); flex-shrink:0; }
        .gs-fa-modal__header h3 { margin:0; color:#f2f4f8; font-size:1.15rem; }
        .gs-fa-modal__close { background:none; border:0; color:#e6edf7; font-size:24px; cursor:pointer; padding:0 6px; line-height:1; }
        .gs-fa-modal__close:hover { color:#fff; }
        .gs-fa-modal__body { padding:20px 24px; overflow-y:auto; flex:1 1 auto; min-height:0; }
        .gs-fa-modal__body .gs-grid { display:grid; grid-template-columns:repeat(2, 1fr); gap:16px; }
        .gs-fa-modal__body .gs-card { background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:10px; }
        .gs-fa-modal__body label { color:#e6edf7; }
        .gs-fa-modal__body .gs-card h4 { margin:0 0 8px 0; }
        .gs-fa-modal__body ul { list-style:none; padding:0; margin:0; }
        .gs-fa-modal__body ul li { padding:3px 0; }
        .gs-fa-modal__footer { display:flex; justify-content:flex-end; gap:12px; padding:16px 24px; border-top:1px solid rgba(255,255,255,0.06); flex-shrink:0; }
        body.gs-fa-modal-open { overflow:hidden; }
        .gs-fa-feedback-error { color:#fca5a5; }
        .gs-fa-feedback-success { color:#a7f3d0; }
    </style>

    <script>
    (function($){
        if (!$) { return; }
        var ajaxurl = window.ajaxurl || '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
        var nonce   = <?php echo wp_json_encode($gs_feature_access_modal_nonce); ?>;
        var $modal  = $('#gs-fa-modal');

        // Portal to body so position:fixed escapes any positioned ancestor
        // (the dashboard tab panel uses backdrop-filter which creates a
        // containing block). Once moved, all opens are viewport-centered.
        if ($modal.length && !$modal.parent('body').length) {
            $modal.appendTo(document.body);
        }

        function setFeedback(msg, type) {
            var $f = $('#gs-fa-modal-feedback');
            $f.removeClass('gs-fa-feedback-error gs-fa-feedback-success');
            if (!msg) { $f.empty(); return; }
            $f.text(msg).addClass(type === 'error' ? 'gs-fa-feedback-error' : 'gs-fa-feedback-success');
        }

        function openModal() {
            $modal.removeAttr('hidden').attr('aria-hidden', 'false').addClass('is-open');
            $('body').addClass('gs-fa-modal-open');
        }

        function closeModal() {
            $modal.removeClass('is-open').attr('hidden', true).attr('aria-hidden', 'true');
            $('body').removeClass('gs-fa-modal-open');
            $('#gs-fa-modal-checkboxes').html('<em>Loading…</em>');
            setFeedback('', null);
        }

        // Search filter — show/hide rows by data-search blob
        $(document).on('input', '#gs-fa-search', function(){
            var q = ($(this).val() || '').toLowerCase().trim();
            var $rows = $('#gs-fa-users-table tbody tr[data-search]');
            var visible = 0;
            $rows.each(function(){
                var match = !q || ($(this).data('search') || '').indexOf(q) !== -1;
                $(this).toggle(match);
                if (match) { visible++; }
            });
            $('#gs-fa-empty').toggle(visible === 0);
        });

        // Open modal — fetch checkbox grid via AJAX
        $(document).on('click', '.gs-fa-open-modal', function(e){
            e.preventDefault();
            var userId = $(this).data('userId');
            if (!userId) { return; }
            $('#gs-fa-modal-user-id').val(userId);
            $('#gs-fa-modal-user').text('Loading user…');
            $('#gs-fa-modal-checkboxes').html('<em>Loading…</em>');
            setFeedback('', null);
            openModal();
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: { action: 'gs_feature_access_form', nonce: nonce, user_id: userId }
            }).done(function(resp){
                if (resp && resp.success && resp.data) {
                    $('#gs-fa-modal-user').text(resp.data.user_label || '');
                    $('#gs-fa-modal-checkboxes').html(resp.data.html || '');
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to load.';
                    setFeedback(msg, 'error');
                }
            }).fail(function(){
                setFeedback('Network error loading user access.', 'error');
            });
        });

        // Parent/child checkbox sync inside the modal
        $(document).on('change', '#gs-fa-modal-checkboxes .gs-parent-checkbox', function(){
            $(this).closest('.gs-card').find('.gs-child-checkbox').prop('checked', this.checked);
        });
        $(document).on('change', '#gs-fa-modal-checkboxes .gs-child-checkbox', function(){
            if (this.checked) {
                $(this).closest('.gs-card').find('.gs-parent-checkbox').prop('checked', true);
            }
        });

        // Dismiss
        $(document).on('click', '[data-gs-fa-dismiss]', function(e){
            e.preventDefault();
            closeModal();
        });
        $(document).on('keydown', function(e){
            if (e.key === 'Escape' && $modal.hasClass('is-open')) { closeModal(); }
        });

        // Invite New User modal — portaled to body on first open so it
        // escapes the dashboard tab panel's backdrop-filter containing block
        // (same pattern as the Manage Access modal). Wraps gs_invite_render_panel().
        var $inviteModal = $('#gs-fa-invite-modal');
        if ($inviteModal.length && !$inviteModal.parent('body').length) {
            $inviteModal.appendTo(document.body);
        }
        function openInviteModal() {
            $inviteModal.removeAttr('hidden').attr('aria-hidden', 'false').addClass('is-open');
            $('body').addClass('gs-fa-modal-open');
        }
        function closeInviteModal() {
            $inviteModal.removeClass('is-open').attr('hidden', true).attr('aria-hidden', 'true');
            if (!$('.gs-fa-modal.is-open').length) {
                $('body').removeClass('gs-fa-modal-open');
            }
        }
        $(document).on('click', '#gs-fa-invite-open', function(e){
            e.preventDefault();
            openInviteModal();
        });
        $(document).on('click', '[data-gs-invite-dismiss]', function(e){
            e.preventDefault();
            closeInviteModal();
        });
        $(document).on('keydown', function(e){
            if (e.key === 'Escape' && $inviteModal.hasClass('is-open')) { closeInviteModal(); }
        });

        // Save — AJAX POST selections
        $(document).on('click', '#gs-fa-modal-save', function(e){
            e.preventDefault();
            var $btn = $(this);
            var userId = $('#gs-fa-modal-user-id').val();
            if (!userId) { return; }
            var slugs = $('#gs-fa-modal-checkboxes input[type="checkbox"]:checked').map(function(){ return $(this).val(); }).get();
            $btn.prop('disabled', true);
            setFeedback('Saving…', null);
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                dataType: 'json',
                traditional: true,
                data: { action: 'gs_feature_access_save', nonce: nonce, user_id: userId, gs_allowed_menus: slugs }
            }).done(function(resp){
                if (resp && resp.success) {
                    setFeedback('Saved.', 'success');
                    setTimeout(closeModal, 600);
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed.';
                    setFeedback(msg, 'error');
                }
            }).fail(function(){
                setFeedback('Network error saving.', 'error');
            }).always(function(){
                $btn.prop('disabled', false);
            });
        });
    })(window.jQuery);
    </script>

    <?php } ?>
</div>
