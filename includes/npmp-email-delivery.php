<?php
// includes/npmp-email-delivery.php

if (!defined('ABSPATH')) exit;

if (!function_exists('npmp_render_email_delivery_page')) {
    function npmp_render_email_delivery_page() {
        // Check user permission
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'nonprofit-manager'));
        }
        
        $message = '';
        $debug_output = '';

        $settings = get_option('npmp_email_delivery_settings', []);
        $method = $settings['method'] ?? 'wp_mail';

        // Handle form actions with nonce verification
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && 
            isset($_POST['_wpnonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'npmp_email_delivery_settings')) {
            
            // Save delivery method
            if (isset($_POST['save_settings'])) {
                $method = sanitize_text_field(wp_unslash($_POST['npmp_email_delivery_method'] ?? 'wp_mail'));
                $settings['method'] = $method;
                update_option('npmp_email_delivery_settings', $settings);
                $message = '<div class="updated"><p>' . esc_html__('Settings saved successfully.', 'nonprofit-manager') . '</p></div>';
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
                update_option('npmp_email_delivery_settings', $settings);
                $message = '<div class="updated"><p>' . esc_html__('SMTP settings saved successfully.', 'nonprofit-manager') . '</p></div>';
            }

            // Test email
            if (isset($_POST['send_test_email'])) {
                delete_option('npmp_smtp_debug_log');
                $to = sanitize_email(wp_unslash($_POST['npmp_test_email_to'] ?? ''));
                $subject = esc_html__('Nonprofit Manager Test Email', 'nonprofit-manager');
                $body = esc_html__('This is a test email sent using your selected delivery method.', 'nonprofit-manager');
                $headers = ['Content-Type: text/plain; charset=UTF-8'];

                $success = wp_mail($to, $subject, $body, $headers);

                if ($success) {
                    $message = '<div class="updated"><p>✅ ' . esc_html__('Test email sent to', 'nonprofit-manager') . ' ' . esc_html($to) . '.</p></div>';
                } else {
                    $debug_log = get_option('npmp_smtp_debug_log', '');
                    $message = '<div class="error"><p>❌ ' . esc_html__('Failed to send test email.', 'nonprofit-manager') . '</p>';
                    if ($debug_log) {
                        $message .= '<pre style="background:#fff;border:1px solid #ccc;padding:10px;max-height:300px;overflow:auto;">' . esc_html($debug_log) . '</pre>';
                    } else {
                        $message .= '<p>' . esc_html__('No debug info available. If using SMTP, enable debug mode and try again.', 'nonprofit-manager') . '</p>';
                    }
                    $message .= '</div>';
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Email Delivery Settings', 'nonprofit-manager') . '</h1>';
        echo wp_kses_post($message);
        echo '<form method="post">';
        wp_nonce_field('npmp_email_delivery_settings');

        // Delivery method dropdown
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th><label for="npmp_email_delivery_method">' . esc_html__('Delivery Method', 'nonprofit-manager') . '</label></th>';
        echo '<td>';
        echo '<select name="npmp_email_delivery_method" id="npmp_email_delivery_method">';
        echo '<option value="wp_mail"' . selected($method, 'wp_mail', false) . '>' . esc_html__('WordPress Default (wp_mail)', 'nonprofit-manager') . '</option>';
        echo '<option value="smtp"' . selected($method, 'smtp', false) . '>' . esc_html__('SMTP', 'nonprofit-manager') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Choose your preferred method to send transactional emails.', 'nonprofit-manager') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        submit_button(esc_html__('Save Delivery Method', 'nonprofit-manager'), 'primary', 'save_settings');
        echo '</form>';

        // Load SMTP form if selected
        if ($method === 'smtp') {
            include_once plugin_dir_path(__FILE__) . 'email/smtp.php';
            npmp_render_smtp_settings_form($settings);
        }

        // Test Email Form
        echo '<hr>';
        echo '<h2>' . esc_html__('Send Test Email', 'nonprofit-manager') . '</h2>';
        echo '<form method="post">';
        wp_nonce_field('npmp_email_delivery_settings');
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Recipient Email', 'nonprofit-manager') . '</th><td><input type="email" name="npmp_test_email_to" value="' . esc_attr(wp_get_current_user()->user_email) . '" class="regular-text" required></td></tr>';
        echo '</table>';
        submit_button(esc_html__('Send Test Email', 'nonprofit-manager'), 'secondary', 'send_test_email');
        echo '</form>';
        echo '</div>';
    }
}