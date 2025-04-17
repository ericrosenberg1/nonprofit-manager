<?php
// includes/payments/npmp-paypal.php

if (!defined('ABSPATH')) exit;

if (get_option('npmp_enable_paypal')) {

    // PayPal SDK is now enqueued in npmp-scripts.php

    // Donation form shortcode
    add_shortcode('npmp_donation_form', function () {
        ob_start();

        $method       = get_option('npmp_paypal_method', 'sdk');
        $mode         = get_option('npmp_paypal_mode', 'live');
        $email_link   = sanitize_email(get_option('npmp_paypal_email'));
        $client_id    = esc_attr(get_option('npmp_paypal_client_id'));

        $title        = get_option('npmp_donation_form_title', 'Support Our Mission');
        $intro        = get_option('npmp_donation_form_intro', 'Your contribution helps us make a difference.');
        $amount_label = get_option('npmp_donation_amount_label', 'Donation Amount');
        $email_label  = get_option('npmp_donation_email_label', 'Your Email');
        $button_label = get_option('npmp_donation_button_label', 'Donate Now');
        $min_amount   = floatval(get_option('npmp_paypal_minimum', 1));

        $frequencies = [];
        if (get_option('npmp_enable_one_time')) $frequencies['one_time'] = 'One-Time';
        if (get_option('npmp_enable_monthly'))  $frequencies['monthly'] = 'Monthly';
        if (get_option('npmp_enable_annual'))   $frequencies['annual'] = 'Annual';

        // Verify the paypal_success parameter with a nonce
        $paypal_success = '';
        if (isset($_GET['paypal_success'], $_GET['_wpnonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'npmp_paypal_success')) {
            $paypal_success = sanitize_text_field(wp_unslash($_GET['paypal_success']));
        }
        if ($paypal_success === '1') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Thank you for your donation!', 'nonprofit-manager') . '</p></div>';
        }

        echo '<div class="npmp-donation-form" style="max-width:500px;">';
        echo '<h3>' . esc_html($title) . '</h3>';
        echo '<p>' . esc_html($intro) . '</p>';
        echo '<form method="post" id="paypal-donation-form" action="">';

        echo '<p><label>' . esc_html($amount_label) . '<br><input type="number" step="0.01" min="' . esc_attr($min_amount) . '" name="amount" id="npmp-donation-amount" required style="width:100%;"></label></p>';
        echo '<p><label>' . esc_html($email_label) . '<br><input type="email" name="email" id="npmp-donation-email" required style="width:100%;"></label></p>';

        if ($method === 'sdk' && !empty($frequencies)) {
            echo '<p><label>Frequency<br><select name="frequency" id="npmp-donation-frequency" required style="width:100%;">';
            foreach ($frequencies as $val => $label) {
                echo '<option value="' . esc_attr($val) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
        }

        // Add nonces for both donation tracking and success redirect
        wp_nonce_field('npmp_paypal_donation_nonce', 'npmp_paypal_donation_nonce_field');
        echo '<input type="hidden" id="npmp_paypal_success_nonce" value="' . esc_attr(wp_create_nonce('npmp_paypal_success')) . '">';

        if ($method === 'email' && $email_link) {
            echo '<input type="hidden" name="npmp_gateway" value="paypal_email">';
            echo '<input type="hidden" id="npmp-paypal-business" value="' . esc_attr($email_link) . '">';
            echo '<p style="margin-top:20px;"><a target="_blank" class="button button-primary" style="width:100%;" onclick="return npmpRedirectToPayPal()">' . esc_html($button_label) . '</a></p>';
        }

        if ($method === 'sdk' && $client_id) {
            echo '<div id="paypal-button-container" style="margin-top:20px;"></div>';
        }

        echo '</form>';
        echo '</div>';
        return ob_get_clean();
    });

    // Log PayPal donations in DB (AJAX)
    add_action('wp_ajax_npmp_log_paypal_donation', 'npmp_handle_paypal_donation');
    add_action('wp_ajax_nopriv_npmp_log_paypal_donation', 'npmp_handle_paypal_donation');

    function npmp_handle_paypal_donation() {
        $nonce = isset($_POST['npmp_paypal_donation_nonce_field']) ? sanitize_text_field(wp_unslash($_POST['npmp_paypal_donation_nonce_field'])) : '';
        if (!wp_verify_nonce($nonce, 'npmp_paypal_donation_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            wp_die();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'npmp_donations';

        $email     = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $amount    = floatval(wp_unslash($_POST['amount'] ?? 0));
        $frequency = sanitize_text_field(wp_unslash($_POST['frequency'] ?? 'one_time'));

        if ($email && $amount > 0) {
            $wpdb->insert($table, [
                'email'      => $email,
                'name'       => '',
                'amount'     => $amount,
                'frequency'  => $frequency,
                'gateway'    => 'paypal',
                'created_at' => current_time('mysql')
            ]);
        }

        wp_die();
    }

    // Render PayPal settings
    add_action('npmp_render_paypal_settings_section', function () {
        $method = get_option('npmp_paypal_method', 'sdk');
        $mode   = get_option('npmp_paypal_mode', 'live');

        echo '<div style="margin-top:20px;">';
        echo '<h3>' . esc_html__('PayPal Settings', 'nonprofit-manager') . '</h3><table class="form-table">';

        echo '<tr><th>' . esc_html__('Integration Method', 'nonprofit-manager') . '</th><td>';
        echo '<label><input type="radio" name="npmp_paypal_method" value="email" ' . checked($method, 'email', false) . '> Email Link</label><br>';
        echo '<label><input type="radio" name="npmp_paypal_method" value="sdk" ' . checked($method, 'sdk', false) . '> Smart Button</label>';
        echo '</td></tr>';

        echo '<tr data-method="email"><th>Email for Donations</th><td><input type="email" name="npmp_paypal_email" value="' . esc_attr(get_option('npmp_paypal_email')) . '" class="regular-text" /></td></tr>';
        echo '<tr data-method="sdk"><th>Client ID</th><td><input type="text" name="npmp_paypal_client_id" value="' . esc_attr(get_option('npmp_paypal_client_id')) . '" class="regular-text" /></td></tr>';
        echo '<tr data-method="sdk"><th>Secret</th><td><input type="text" name="npmp_paypal_secret" value="' . esc_attr(get_option('npmp_paypal_secret')) . '" class="regular-text" /></td></tr>';

        echo '<tr data-method="sdk"><th>Environment</th><td>';
        echo '<label><input type="radio" name="npmp_paypal_mode" value="live" ' . checked($mode, 'live', false) . '> Live</label><br>';
        echo '<label><input type="radio" name="npmp_paypal_mode" value="sandbox" ' . checked($mode, 'sandbox', false) . '> Sandbox</label>';
        echo '</td></tr>';

        echo '<tr><th>Minimum</th><td><input type="number" step="0.01" name="npmp_paypal_minimum" value="' . esc_attr(get_option('npmp_paypal_minimum', 1)) . '" class="small-text" /> USD</td></tr>';
        echo '</table></div>';
    });
}
