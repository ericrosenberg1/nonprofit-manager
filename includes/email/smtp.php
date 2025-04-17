<?php
// includes/email/smtp.php

if (!defined('ABSPATH')) exit;

/**
 * Apply SMTP settings to PHPMailer if SMTP is selected.
 */
add_action('phpmailer_init', function ($phpmailer) {
    $settings = get_option('npmp_email_delivery_settings');

    if (!is_array($settings) || ($settings['method'] ?? '') !== 'smtp') return;

    $required = ['host', 'username', 'password', 'from_email'];
    foreach ($required as $key) {
        if (empty($settings[$key])) return;
    }

    $host = sanitize_text_field($settings['host']);
    $port = (int) $settings['port'];
    $secure = in_array($settings['secure'], ['ssl', 'tls']) ? $settings['secure'] : '';
    $username = sanitize_text_field($settings['username']);
    $password = $settings['password'];
    $from_email = sanitize_email($settings['from_email']);
    $from_name = sanitize_text_field($settings['from_name'] ?? '');
    $debug = !empty($settings['debug']);

    $phpmailer->isSMTP();
    $phpmailer->Host = $host;
    $phpmailer->Port = $port;
    $phpmailer->SMTPAuth = true;
    $phpmailer->Username = $username;
    $phpmailer->Password = $password;
    $phpmailer->SMTPSecure = $secure;
    $phpmailer->SMTPAutoTLS = ($secure === 'tls');

    $phpmailer->setFrom($from_email, $from_name ?: $from_email, false);
    $phpmailer->From = $from_email;
    $phpmailer->FromName = $from_name ?: $from_email;

    if ($debug) {
        update_option('npmp_smtp_debug_log', '');
        $phpmailer->SMTPDebug = 2;
        $phpmailer->Debugoutput = function ($str, $level) {
            static $log = '';
            $log .= "$level: $str\n";
            update_option('npmp_smtp_debug_log', $log);
        };
    }
});

/**
 * Render the SMTP settings form. Called from npmp-email-delivery.php if SMTP is selected.
 */
function npmp_render_smtp_settings_form($settings = []) {
    echo '<h2>SMTP Configuration</h2>';
    echo '<form method="post">';
    wp_nonce_field('npmp_email_delivery_settings');

    echo '<table class="form-table">';
    echo '<tr><th>SMTP Host</th><td><input type="text" name="host" value="' . esc_attr($settings['host'] ?? '') . '" class="regular-text"></td></tr>';
    echo '<tr><th>SMTP Port</th><td><input type="number" name="port" value="' . esc_attr($settings['port'] ?? 587) . '" class="small-text"></td></tr>';
    echo '<tr><th>Encryption</th><td><select name="secure">';
    echo '<option value=""' . selected($settings['secure'] ?? '', '', false) . '>None</option>';
    echo '<option value="ssl"' . selected($settings['secure'] ?? '', 'ssl', false) . '>SSL</option>';
    echo '<option value="tls"' . selected($settings['secure'] ?? '', 'tls', false) . '>TLS</option>';
    echo '</select></td></tr>';
    echo '<tr><th>Use Authentication</th><td><input type="checkbox" name="auth" value="1"' . checked($settings['auth'] ?? 0, 1, false) . '> Yes</td></tr>';
    echo '<tr><th>SMTP Username</th><td><input type="text" name="username" value="' . esc_attr($settings['username'] ?? '') . '" class="regular-text"></td></tr>';
    echo '<tr><th>SMTP Password</th><td><input type="password" name="password" value="' . esc_attr($settings['password'] ?? '') . '" class="regular-text" autocomplete="new-password"></td></tr>';
    echo '<tr><th>From Email</th><td><input type="email" name="from_email" value="' . esc_attr($settings['from_email'] ?? '') . '" class="regular-text"></td></tr>';
    echo '<tr><th>From Name</th><td><input type="text" name="from_name" value="' . esc_attr($settings['from_name'] ?? '') . '" class="regular-text"></td></tr>';
    echo '<tr><th>Debug</th><td><input type="checkbox" name="debug" value="1"' . checked($settings['debug'] ?? 0, 1, false) . '> Enable debug logging</td></tr>';
    echo '</table>';

    submit_button('Save SMTP Settings', 'secondary', 'save_smtp_settings');
    echo '</form>';
}
