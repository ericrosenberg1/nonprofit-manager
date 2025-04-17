<?php
// includes/np-email-delivery.php

if (!defined('ABSPATH')) exit;

function np_render_email_delivery_page() {
    $message = '';
    $debug_output = '';

    $settings = get_option('np_email_delivery_settings', []);
    $method = $settings['method'] ?? 'wordpress';

    // Handle form actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('np_email_delivery_settings')) {
        // Save delivery method
        if (isset($_POST['save_settings'])) {
            $method = sanitize_text_field(wp_unslash($_POST['np_email_delivery_method'] ?? 'wordpress'));
            $settings['method'] = $method;
            update_option('np_email_delivery_settings', $settings);
            $message = '<div class="updated"><p>Settings saved successfully.</p></div>';
        }

        // Save SMTP-specific settings if selected
        if ($method === 'smtp' && isset($_POST['save_smtp_settings'])) {
            $settings['host'] = sanitize_text_field(wp_unslash($_POST['host'] ?? ''));
            $settings['port'] = intval(wp_unslash($_POST['port'] ?? 587));
            $settings['secure'] = sanitize_text_field(wp_unslash($_POST['secure'] ?? ''));
            $settings['auth'] = isset($_POST['auth']) ? 1 : 0;
            $settings['username'] = sanitize_text_field(wp_unslash($_POST['username'] ?? ''));
            $settings['password'] = sanitize_text_field(wp_unslash($_POST['password'] ?? ''));
            $settings['from_email'] = sanitize_email(wp_unslash($_POST['from_email'] ?? ''));
            $settings['from_name'] = sanitize_text_field(wp_unslash($_POST['from_name'] ?? ''));
            $settings['debug'] = isset($_POST['debug']) ? 1 : 0;
            update_option('np_email_delivery_settings', $settings);
            $message = '<div class="updated"><p>SMTP settings saved successfully.</p></div>';
        }

        // Test email
        if (isset($_POST['send_test_email'])) {
            delete_option('np_smtp_debug_log');
            $to = sanitize_email(wp_unslash($_POST['np_test_email_to'] ?? ''));
            $subject = 'Nonprofit Manager Test Email';
            $body = 'This is a test email sent using your selected delivery method.';
            $headers = ['Content-Type: text/plain; charset=UTF-8'];

            $success = wp_mail($to, $subject, $body, $headers);

            if ($success) {
                $message = '<div class="updated"><p>✅ Test email sent to ' . esc_html($to) . '.</p></div>';
            } else {
                $debug_log = get_option('np_smtp_debug_log', '');
                $message = '<div class="error"><p>❌ Failed to send test email.</p>';
                if ($debug_log) {
                    $message .= '<pre style="background:#fff;border:1px solid #ccc;padding:10px;max-height:300px;overflow:auto;">' . esc_html($debug_log) . '</pre>';
                } else {
                    $message .= '<p>No debug info available. If using SMTP, enable debug mode and try again.</p>';
                }
                $message .= '</div>';
            }
        }
    }

    echo '<div class="wrap">';
    echo '<h1>Email Delivery Settings</h1>';
    echo wp_kses_post($message);
    echo '<form method="post">';
    wp_nonce_field('np_email_delivery_settings');

    // Delivery method dropdown
    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th><label for="np_email_delivery_method">Delivery Method</label></th>';
    echo '<td>';
    echo '<select name="np_email_delivery_method" id="np_email_delivery_method">';
    echo '<option value="wordpress"' . selected($method, 'wordpress', false) . '>WordPress Default (wp_mail)</option>';
    echo '<option value="smtp"' . selected($method, 'smtp', false) . '>SMTP</option>';
    echo '</select>';
    echo '<p class="description">Choose your preferred method to send transactional emails.</p>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';

    submit_button('Save Delivery Method', 'primary', 'save_settings');
    echo '</form>';

    // Load SMTP form if selected
    if ($method === 'smtp') {
        include_once plugin_dir_path(__FILE__) . 'email/smtp.php';
        np_render_smtp_settings_form($settings);
    }

    // Test Email Form
    echo '<hr>';
    echo '<h2>Send Test Email</h2>';
    echo '<form method="post">';
    wp_nonce_field('np_email_delivery_settings');
    echo '<table class="form-table">';
    echo '<tr><th>Recipient Email</th><td><input type="email" name="np_test_email_to" class="regular-text" required></td></tr>';
    echo '</table>';
    submit_button('Send Test Email', 'secondary', 'send_test_email');
    echo '</form>';
    echo '</div>';
}
