<?php
/**
 * Profile Invite — Admin Settings.
 *
 * Stores the OAuth client_id + client_secret for Google and Microsoft.
 * Rendered as the "Invites" tab inside the Social Profiles page
 * (sn_render_network_settings_page) — no standalone menu entry.
 *
 * Apple has no Contacts OAuth, so there's nothing to configure for Apple —
 * the Apple integration is a vCard upload handled entirely client-side.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', 'gs_invite_settings_register_setting' );
function gs_invite_settings_register_setting() {
    register_setting( 'gs_invite_oauth_credentials_group', 'gs_invite_oauth_credentials', array(
        'type'              => 'array',
        'sanitize_callback' => 'gs_invite_settings_sanitize',
        'default'           => array(),
    ) );
}

function gs_invite_settings_sanitize( $input ) {
    $clean = array();
    foreach ( array( 'google', 'microsoft' ) as $p ) {
        $clean[ $p ] = array(
            'client_id'     => isset( $input[ $p ]['client_id'] )     ? trim( sanitize_text_field( $input[ $p ]['client_id'] ) )     : '',
            'client_secret' => isset( $input[ $p ]['client_secret'] ) ? trim( sanitize_text_field( $input[ $p ]['client_secret'] ) ) : '',
        );
    }
    return $clean;
}

function gs_invite_settings_render_inline() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $opts        = get_option( 'gs_invite_oauth_credentials', array() );
    $google      = isset( $opts['google'] )    ? $opts['google']    : array( 'client_id' => '', 'client_secret' => '' );
    $microsoft   = isset( $opts['microsoft'] ) ? $opts['microsoft'] : array( 'client_id' => '', 'client_secret' => '' );
    $redirect_uri = rest_url( 'gs/v1/invite/oauth/callback' );
    ?>
        <p style="max-width:760px;color:#94a3b8;line-height:1.6;">
            <?php esc_html_e( 'These credentials power the "Connect Google" and "Connect Outlook" buttons on each member\'s Connections → Invite tab. Apple has no Contacts OAuth — Apple users upload a vCard (.vcf) instead.', 'gend-society' ); ?>
        </p>

        <div class="notice notice-info" style="padding:12px 16px;">
            <p style="margin:0 0 6px;"><strong><?php esc_html_e( 'Redirect URI to register with both providers:', 'gend-society' ); ?></strong></p>
            <code style="display:block;padding:8px;background:#0b0e14;color:#7dd3fc;border-radius:6px;font-size:0.85rem;"><?php echo esc_html( $redirect_uri ); ?></code>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'gs_invite_oauth_credentials_group' ); ?>

            <h2><?php esc_html_e( 'Google', 'gend-society' ); ?></h2>
            <p style="max-width:760px;color:#94a3b8;">
                <?php
                printf(
                    /* translators: 1: opening anchor 2: closing anchor */
                    esc_html__( 'Create OAuth 2.0 credentials at the %1$sGoogle Cloud Console%2$s. Enable the "People API" for the project. Authorized redirect URI must match exactly.', 'gend-society' ),
                    '<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">',
                    '</a>'
                );
                ?>
                <br>
                <strong><?php esc_html_e( 'Required scope:', 'gend-society' ); ?></strong>
                <code>https://www.googleapis.com/auth/contacts.readonly</code>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="gs-google-cid"><?php esc_html_e( 'Client ID', 'gend-society' ); ?></label></th>
                    <td><input id="gs-google-cid" type="text" class="regular-text" name="gs_invite_oauth_credentials[google][client_id]" value="<?php echo esc_attr( $google['client_id'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gs-google-cs"><?php esc_html_e( 'Client Secret', 'gend-society' ); ?></label></th>
                    <td><input id="gs-google-cs" type="password" class="regular-text" name="gs_invite_oauth_credentials[google][client_secret]" value="<?php echo esc_attr( $google['client_secret'] ); ?>"></td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Microsoft (Outlook)', 'gend-society' ); ?></h2>
            <p style="max-width:760px;color:#94a3b8;">
                <?php
                printf(
                    /* translators: 1: opening anchor 2: closing anchor */
                    esc_html__( 'Register an application at the %1$sAzure Portal → App registrations%2$s. Use "Accounts in any organizational directory and personal Microsoft accounts" so personal Outlook users can connect. Add the redirect URI above as a Web platform.', 'gend-society' ),
                    '<a href="https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">',
                    '</a>'
                );
                ?>
                <br>
                <strong><?php esc_html_e( 'Required delegated scopes:', 'gend-society' ); ?></strong>
                <code>offline_access</code> <code>Contacts.Read</code> <code>User.Read</code>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="gs-ms-cid"><?php esc_html_e( 'Application (client) ID', 'gend-society' ); ?></label></th>
                    <td><input id="gs-ms-cid" type="text" class="regular-text" name="gs_invite_oauth_credentials[microsoft][client_id]" value="<?php echo esc_attr( $microsoft['client_id'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gs-ms-cs"><?php esc_html_e( 'Client Secret value', 'gend-society' ); ?></label></th>
                    <td>
                        <input id="gs-ms-cs" type="password" class="regular-text" name="gs_invite_oauth_credentials[microsoft][client_secret]" value="<?php echo esc_attr( $microsoft['client_secret'] ); ?>">
                        <p class="description"><?php esc_html_e( 'Use the secret VALUE shown immediately after creation in Azure — not the secret ID.', 'gend-society' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Apple', 'gend-society' ); ?></h2>
            <p style="max-width:760px;color:#94a3b8;line-height:1.6;">
                <?php esc_html_e( 'Apple does not provide a Contacts API — "Sign in with Apple" only returns the signed-in user\'s email and name. The Apple Contacts integration on the Invite tab is therefore a vCard (.vcf) upload, parsed in the browser. Apple Contacts.app exports your address book as a single .vcf file via File → Export → Export vCard. No configuration is required here.', 'gend-society' ); ?>
            </p>

            <?php submit_button( __( 'Save Settings', 'gend-society' ) ); ?>
        </form>
    <?php
}
