<?php
/**
 * gend.me mail service — container side.
 *
 * A paired container sends its email through gend.me's mail service (the hub's Mailgun account) without any mail
 * integration of its own: WordPress mail and Talk Flows (email-manager) agent mail are handed to
 * POST <gend.me>/wp-json/gend-mail/v1/relay with the install's pairing credentials, and the hub meters them against
 * the site's Emails plans. Agent mail over the monthly allowance is held in the Talk Flows outbox (retried hourly);
 * account mail always goes out. Once a day the container reports how much Talk Flows data it stores.
 *
 * Not used when the container has its own SMTP integration switched on (email-manager's SMTP settings, or the
 * gend_mail_transport option set to "smtp"), or a Mailgun key of its own (gend-mailgun mu-plugin), or isn't paired.
 * No-op on gend.me itself, which has no pairing options.
 *
 * @package GenD_Society
 */

defined('ABSPATH') || exit;

/** Pairing credentials + hub base URL, or null when this install isn't paired. */
function gend_society_mail_relay_creds() {
    $id    = (string) get_option('gend_society_install_id', '');
    $token = (string) get_option('gend_society_install_token', '');
    $base  = (string) get_option('gend_society_gend_base_url', '');
    return ($id !== '' && $token !== '' && $base !== '') ? array('id' => $id, 'token' => $token, 'base' => untrailingslashit($base)) : null;
}

/** Whether this container's mail goes through the gend.me relay. */
function gend_society_mail_relay_active() {
    if (!gend_society_mail_relay_creds()) return false;
    if (function_exists('gend_mailgun_enabled') && gend_mailgun_enabled()) return false; // its own Mailgun key
    $choice = (string) get_option('gend_mail_transport', '');
    if ($choice === 'smtp') return false;
    if ($choice !== 'gend') {
        $smtp = get_option('em_smtp_settings');
        if (is_array($smtp) && ($smtp['enabled'] ?? '') === 'yes' && !empty($smtp['host'])) return false;
    }
    return (bool) apply_filters('gend_society_mail_relay_active', true);
}

/** POST to a gend.me mail-service route. Returns [status, data] or WP_Error. */
function gend_society_mail_relay_call($route, array $body, $timeout = 30) {
    // RUN-01: no gend.me request before the owner consented (always true on hub/container).
    if (function_exists('gend_society_remote_allowed') && !gend_society_remote_allowed('mail')) return new WP_Error('not_consented', 'This site has not been connected to gend.me yet.');
    $c = gend_society_mail_relay_creds();
    if (!$c) return new WP_Error('not_paired', 'This install is not paired with gend.me.');
    $resp = wp_remote_post($c['base'] . '/wp-json/gend-mail/v1/' . ltrim($route, '/'), array(
        'timeout' => $timeout,
        'headers' => array('Content-Type' => 'application/json', 'X-Gend-Install' => $c['id'], 'X-Gend-Install-Token' => $c['token']),
        'body'    => wp_json_encode($body),
    ));
    if (is_wp_error($resp)) return $resp;
    $data = json_decode((string) wp_remote_retrieve_body($resp), true);
    return array((int) wp_remote_retrieve_response_code($resp), is_array($data) ? $data : array());
}

/** Splits a header list (string or array) into lowercase name => value(s). */
function gend_society_mail_relay_headers($headers) {
    $lines = is_array($headers) ? $headers : preg_split("/\r\n|\r|\n/", (string) $headers);
    $out = array('cc' => array(), 'bcc' => array(), 'from' => '', 'reply-to' => '', 'content-type' => '');
    foreach ((array) $lines as $h) {
        if (!is_string($h) || strpos($h, ':') === false) continue;
        list($k, $v) = array_map('trim', explode(':', $h, 2));
        $k = strtolower($k);
        if ($k === 'cc' || $k === 'bcc') $out[$k] = array_merge($out[$k], array_map('trim', explode(',', $v)));
        elseif (isset($out[$k])) $out[$k] = $v;
    }
    return $out;
}

// WordPress mail → the relay (account mail: always sent, counted).
add_filter('pre_wp_mail', function ($return, $atts) {
    if ($return !== null || !gend_society_mail_relay_active()) return $return;
    $to  = is_array($atts['to']) ? $atts['to'] : array_filter(array_map('trim', explode(',', (string) $atts['to'])));
    $h   = gend_society_mail_relay_headers($atts['headers'] ?? '');
    $msg = (string) ($atts['message'] ?? '');
    $html = stripos($h['content-type'] ?: (string) apply_filters('wp_mail_content_type', 'text/plain'), 'text/html') !== false // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
        || (bool) preg_match('#<(html|body|div|table|p|br|a)\b#i', $msg);
    $from = $h['from'];
    if ($from === '') {
        $from_email = (string) apply_filters('wp_mail_from', 'no-reply@' . preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST))); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
        $from_name  = (string) apply_filters('wp_mail_from_name', get_bloginfo('name')); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
        $from = ($from_name !== '' ? $from_name . ' ' : '') . '<' . $from_email . '>';
    }
    $attachments = array();
    $files = is_array($atts['attachments'] ?? null) ? $atts['attachments'] : array_filter(array_map('trim', explode("\n", (string) ($atts['attachments'] ?? ''))));
    foreach ($files as $f) {
        if (is_string($f) && is_readable($f)) $attachments[] = array('filename' => basename($f), 'content_type' => (string) (wp_check_filetype($f)['type'] ?: 'application/octet-stream'), 'content_b64' => base64_encode((string) file_get_contents($f)));
    }
    $res = gend_society_mail_relay_call('relay', array(
        'to' => array_values($to), 'cc' => $h['cc'], 'bcc' => $h['bcc'], 'from' => $from, 'reply_to' => $h['reply-to'],
        'subject' => (string) ($atts['subject'] ?? ''), ($html ? 'html' : 'text') => $msg, 'attachments' => $attachments, 'class' => 'system',
    ));
    if (is_wp_error($res) || $res[0] < 200 || $res[0] >= 300) {
        $err = is_wp_error($res) ? $res->get_error_message() : ('gend.me mail service: HTTP ' . $res[0] . ' ' . ($res[1]['message'] ?? ''));
        error_log('[gend-society mail] ' . $err);
        do_action('wp_mail_failed', new WP_Error('gend_mail_relay', $err, array('to' => $to))); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
        return false;
    }
    do_action('wp_mail_succeeded', array('to' => $to, 'subject' => $atts['subject'] ?? '', 'transport' => 'gend-relay')); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
    return true;
}, 20, 2);

// Talk Flows agent mail → the relay (metered; held over the allowance).
add_filter('em_inbox_outq_pre_submit', function ($pre, $m) {
    if ($pre !== null || !gend_society_mail_relay_active()) return $pre;
    $atts = array();
    foreach ((array) ($m['attachments'] ?? array()) as $a) {
        if (!empty($a['content_b64'])) $atts[] = array('filename' => (string) ($a['filename'] ?? 'attachment'), 'content_type' => (string) ($a['content_type'] ?? ''), 'content_b64' => (string) $a['content_b64']);
    }
    $body = array(
        'to' => array_values((array) $m['to']), 'cc' => (array) ($m['cc'] ?? array()), 'bcc' => (array) ($m['bcc'] ?? array()),
        'from' => (string) $m['from'], 'subject' => (string) $m['subject'], 'attachments' => $atts, 'class' => 'agent',
    );
    if ((string) ($m['body_html'] ?? '') !== '') $body['html'] = (string) $m['body_html'];
    if ((string) ($m['body_plain'] ?? '') !== '') $body['text'] = (string) $m['body_plain'];
    $res = gend_society_mail_relay_call('relay', $body);
    if (is_wp_error($res)) return array('ok' => false, 'http' => 0, 'error' => $res->get_error_message(), 'relay' => null);
    if ($res[0] === 429 && !empty($res[1]['held'])) {
        update_option('gend_society_mail_relay_state', array('over' => true, 'upgrade_url' => (string) ($res[1]['upgrade_url'] ?? ''), 'at' => time()), false);
        return array('ok' => false, 'held' => true, 'http' => 429, 'error' => 'Held: this site reached its monthly email allowance. Upgrade its Emails plan on gend.me to send it now.', 'relay' => null);
    }
    if ($res[0] >= 200 && $res[0] < 300) {
        delete_option('gend_society_mail_relay_state');
        return array('ok' => true, 'http' => $res[0], 'error' => null, 'relay' => array('via' => 'gend.me'), 'relayed' => true);
    }
    return array('ok' => false, 'http' => $res[0], 'error' => 'gend.me mail service: ' . ($res[1]['message'] ?? ('HTTP ' . $res[0])), 'relay' => null);
}, 20, 2);

// Daily: report how much Talk Flows data this container stores (its Emails storage allowance is checked on gend.me).
add_action('gend_society_mail_storage_report', function () {
    if (!gend_society_mail_relay_creds()) return;
    global $wpdb;
    $t = $wpdb->prefix . 'gdc_inbox_raw';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return;
    $bytes = (int) $wpdb->get_var("SELECT COALESCE(SUM(size_bytes + COALESCE(LENGTH(attachments_json),0) + COALESCE(LENGTH(raw_headers),0)),0) FROM {$t}");
    $res = gend_society_mail_relay_call('storage', array('bytes' => $bytes), 15);
    if (!is_wp_error($res) && $res[0] === 200) update_option('gend_society_mail_service_state', $res[1], false);
});
add_action('init', function () {
    if (!wp_next_scheduled('gend_society_mail_storage_report')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'gend_society_mail_storage_report');
}, 40);

// The site's admins see when agent mail is being held, with the upgrade link.
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $st = get_option('gend_society_mail_relay_state');
    if (!is_array($st) || empty($st['over'])) return;
    echo '<div class="notice notice-warning"><p>' . esc_html__('This site reached its monthly email allowance: new mail from your agents and Talk Flows inboxes is waiting in the outbox.', 'gend-society')
        . (!empty($st['upgrade_url']) ? ' <a href="' . esc_url($st['upgrade_url']) . '" target="_blank" rel="noopener">' . esc_html__('Upgrade your Emails plan', 'gend-society') . '</a>' : '') . '</p></div>';
});
