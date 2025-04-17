<?php
// includes/npmp-payments-settings.php

if (!defined('ABSPATH')) exit;

// Conditionally load PayPal logic
if (get_option('npmp_enable_paypal')) {
    include_once plugin_dir_path(__FILE__) . 'payments/npmp-paypal.php';
}

function npmp_render_payment_settings_page() {
    // Check if user has permission to access this page
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'nonprofit-manager'));
    }
    if (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['npmp_payment_settings_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['npmp_payment_settings_nonce'])), 'npmp_payment_settings')
    ) {
        update_option('npmp_donation_form_title', sanitize_text_field(wp_unslash($_POST['npmp_donation_form_title'] ?? '')));
        update_option('npmp_donation_form_intro', sanitize_textarea_field(wp_unslash($_POST['npmp_donation_form_intro'] ?? '')));
        update_option('npmp_donation_amount_label', sanitize_text_field(wp_unslash($_POST['npmp_donation_amount_label'] ?? '')));
        update_option('npmp_donation_email_label', sanitize_text_field(wp_unslash($_POST['npmp_donation_email_label'] ?? '')));
        update_option('npmp_donation_button_label', sanitize_text_field(wp_unslash($_POST['npmp_donation_button_label'] ?? '')));

        update_option('npmp_donation_page_id', intval($_POST['npmp_donation_page_id'] ?? 0));
        update_option('npmp_enable_one_time', isset($_POST['npmp_enable_one_time']) ? 1 : 0);
        update_option('npmp_enable_monthly', isset($_POST['npmp_enable_monthly']) ? 1 : 0);
        update_option('npmp_enable_annual', isset($_POST['npmp_enable_annual']) ? 1 : 0);

        $selected_gateway = sanitize_text_field(wp_unslash($_POST['npmp_gateway'] ?? ''));
        update_option('npmp_enable_paypal', $selected_gateway === 'paypal' ? 1 : 0);

        if ($selected_gateway === 'paypal') {
            update_option('npmp_paypal_method', sanitize_text_field(wp_unslash($_POST['npmp_paypal_method'] ?? 'sdk')));
            update_option('npmp_paypal_email', sanitize_email(wp_unslash($_POST['npmp_paypal_email'] ?? '')));
            update_option('npmp_paypal_client_id', sanitize_text_field(wp_unslash($_POST['npmp_paypal_client_id'] ?? '')));
            update_option('npmp_paypal_secret', sanitize_text_field(wp_unslash($_POST['npmp_paypal_secret'] ?? '')));
            update_option('npmp_paypal_mode', sanitize_text_field(wp_unslash($_POST['npmp_paypal_mode'] ?? 'live')));
            update_option('npmp_paypal_minimum', floatval($_POST['npmp_paypal_minimum'] ?? 1));
        } else {
            update_option('npmp_enable_paypal', 0);
        }
    }

    $paypal_enabled    = get_option('npmp_enable_paypal', 0);
    $one_time_enabled  = get_option('npmp_enable_one_time', 1);
    $monthly_enabled   = get_option('npmp_enable_monthly', 0);
    $annual_enabled    = get_option('npmp_enable_annual', 0);

    echo '<div class="wrap"><h1>Payment Gateway Settings</h1>';
    echo '<p>Use this page to customize your donation form, set donation options, and enable your preferred payment gateway. Only one gateway can be enabled at a time for simplicity.</p>';

    echo '<form method="post">';
    wp_nonce_field('npmp_payment_settings', 'npmp_payment_settings_nonce');

    echo '<h2>Donation Page</h2>';
    echo '<p>Select a WordPress page where the donation form should be auto-inserted. Or use the shortcode <code>[npmp_donation_form]</code>.</p>';
    wp_dropdown_pages([
        'name' => 'npmp_donation_page_id',
        'selected' => absint(get_option('npmp_donation_page_id')),
        'show_option_none' => 'None (no default donation page)',
    ]);

    echo '<h2>Choose Payment Gateway</h2>';
    echo '<p>Select the gateway to process donations.</p>';
    echo '<label><input type="radio" name="npmp_gateway" value="" ' . checked($paypal_enabled, 0, false) . '> None</label><br>';
    echo '<label><input type="radio" name="npmp_gateway" value="paypal" ' . checked($paypal_enabled, 1, false) . '> PayPal</label><br>';

    echo '<div id="npmp-paypal-settings" style="' . ($paypal_enabled ? 'display:block' : 'display:none') . ';">';
    do_action('npmp_render_paypal_settings_section');
    echo '</div>';

    echo '<h2>Customize Donation Form</h2>';
    echo '<table class="form-table">';
    echo '<tr><th>Form Title</th><td><input type="text" name="npmp_donation_form_title" value="' . esc_attr(get_option('npmp_donation_form_title', 'Support Our Mission')) . '" class="regular-text" /></td></tr>';
    echo '<tr><th>Intro Text</th><td><textarea name="npmp_donation_form_intro" class="large-text" rows="3">' . esc_textarea(get_option('npmp_donation_form_intro', 'Your contribution helps us make a difference.')) . '</textarea></td></tr>';
    echo '<tr><th>Amount Label</th><td><input type="text" name="npmp_donation_amount_label" value="' . esc_attr(get_option('npmp_donation_amount_label', 'Donation Amount')) . '" class="regular-text" /></td></tr>';
    echo '<tr><th>Email Label</th><td><input type="text" name="npmp_donation_email_label" value="' . esc_attr(get_option('npmp_donation_email_label', 'Your Email')) . '" class="regular-text" /></td></tr>';
    echo '<tr><th>Submit Button Label</th><td><input type="text" name="npmp_donation_button_label" value="' . esc_attr(get_option('npmp_donation_button_label', 'Donate Now')) . '" class="regular-text" /></td></tr>';
    echo '</table>';

    echo '<h2>Donation Frequencies</h2>';
    echo '<label><input type="checkbox" name="npmp_enable_one_time" value="1"' . checked($one_time_enabled, 1, false) . '> One-Time</label><br>';
    echo '<label><input type="checkbox" name="npmp_enable_monthly" value="1"' . checked($monthly_enabled, 1, false) . '> Monthly</label><br>';
    echo '<label><input type="checkbox" name="npmp_enable_annual" value="1"' . checked($annual_enabled, 1, false) . '> Annual</label><br>';

    submit_button('Save Payment Settings');
    echo '</form></div>';

}

add_filter('the_content', function ($content) {
    if (is_page() && get_the_ID() == get_option('npmp_donation_page_id')) {
        return $content . do_shortcode('[npmp_donation_form]');
    }
    return $content;
});
