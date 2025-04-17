<?php
// includes/payments/np-paypal.php

if (!defined('ABSPATH')) exit;

if (get_option('np_enable_paypal')) {

    // Enqueue PayPal SDK
    function np_enqueue_paypal_sdk() {
        $method = get_option('np_paypal_method', 'sdk');
        if ($method !== 'sdk') return;

        $client_id = esc_attr(get_option('np_paypal_client_id'));
        $mode = get_option('np_paypal_mode', 'live');
        $sdk_url = 'https://www.paypal.com/sdk/js?client-id=' . $client_id . '&currency=USD';
        if ($mode === 'sandbox') {
            $sdk_url .= '&debug=true';
        }

        wp_enqueue_script('np-paypal-sdk', $sdk_url, [], '1.0.0', true);
    }
    add_action('wp_enqueue_scripts', 'np_enqueue_paypal_sdk');

    // Donation form shortcode
    add_shortcode('np_donation_form', function () {
        ob_start();

        $method       = get_option('np_paypal_method', 'sdk');
        $mode         = get_option('np_paypal_mode', 'live');
        $email_link   = sanitize_email(get_option('np_paypal_email'));
        $client_id    = esc_attr(get_option('np_paypal_client_id'));

        $title        = get_option('np_donation_form_title', 'Support Our Mission');
        $intro        = get_option('np_donation_form_intro', 'Your contribution helps us make a difference.');
        $amount_label = get_option('np_donation_amount_label', 'Donation Amount');
        $email_label  = get_option('np_donation_email_label', 'Your Email');
        $button_label = get_option('np_donation_button_label', 'Donate Now');
        $min_amount   = floatval(get_option('np_paypal_minimum', 1));

        $frequencies = [];
        if (get_option('np_enable_one_time')) $frequencies['one_time'] = 'One-Time';
        if (get_option('np_enable_monthly'))  $frequencies['monthly'] = 'Monthly';
        if (get_option('np_enable_annual'))   $frequencies['annual'] = 'Annual';

        $paypal_success = isset($_GET['paypal_success']) ? sanitize_text_field(wp_unslash($_GET['paypal_success'])) : '';
        if ($paypal_success === '1') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Thank you for your donation!', 'nonprofit-manager') . '</p></div>';
        }

        echo '<div class="np-donation-form" style="max-width:500px;">';
        echo '<h3>' . esc_html($title) . '</h3>';
        echo '<p>' . esc_html($intro) . '</p>';
        echo '<form method="post" id="paypal-donation-form" action="">';

        echo '<p><label>' . esc_html($amount_label) . '<br><input type="number" step="0.01" min="' . esc_attr($min_amount) . '" name="amount" id="np-donation-amount" required style="width:100%;"></label></p>';
        echo '<p><label>' . esc_html($email_label) . '<br><input type="email" name="email" id="np-donation-email" required style="width:100%;"></label></p>';

        if ($method === 'sdk' && !empty($frequencies)) {
            echo '<p><label>Frequency<br><select name="frequency" id="np-donation-frequency" required style="width:100%;">';
            foreach ($frequencies as $val => $label) {
                echo '<option value="' . esc_attr($val) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
        }

        wp_nonce_field('np_paypal_donation_nonce', 'np_paypal_donation_nonce_field');

        if ($method === 'email' && $email_link) {
            echo '<input type="hidden" name="np_gateway" value="paypal_email">';
            echo '<p style="margin-top:20px;"><a target="_blank" class="button button-primary" style="width:100%;" onclick="return npRedirectToPayPal()">' . esc_html($button_label) . '</a></p>';
            echo '<script type="text/javascript">
                function npRedirectToPayPal() {
                    const amount = document.getElementById("np-donation-amount").value;
                    if (!amount || parseFloat(amount) < ' . esc_js($min_amount) . ') {
                        alert("Minimum donation is $' . esc_js(number_format($min_amount, 2)) . '");
                        return false;
                    }
                    const email = document.getElementById("np-donation-email").value;
                    const url = new URL("https://www.paypal.com/donate");
                    url.searchParams.set("business", "' . esc_js($email_link) . '");
                    url.searchParams.set("amount", amount);
                    url.searchParams.set("currency_code", "USD");
                    window.open(url.toString(), "_blank");
                    return false;
                }
            </script>';
        }

        if ($method === 'sdk' && $client_id) {
            echo '<div id="paypal-button-container" style="margin-top:20px;"></div>';
            echo '<script type="text/javascript">
                document.addEventListener("DOMContentLoaded", function () {
                    if (typeof paypal !== "undefined") {
                        paypal.Buttons({
                            createOrder: function(data, actions) {
                                const amount = document.getElementById("np-donation-amount").value;
                                if (!amount || parseFloat(amount) < ' . esc_js($min_amount) . ') {
                                    alert("Minimum donation is $' . esc_js(number_format($min_amount, 2)) . '");
                                    return;
                                }
                                return actions.order.create({
                                    purchase_units: [{
                                        amount: { value: amount }
                                    }]
                                });
                            },
                            onApprove: function(data, actions) {
                                return actions.order.capture().then(function(details) {
                                    const email = document.getElementById("np-donation-email").value;
                                    const amount = document.getElementById("np-donation-amount").value;
                                    const freqEl = document.getElementById("np-donation-frequency");
                                    const frequency = freqEl ? freqEl.value : "one_time";
                                    const nonce = document.querySelector("input[name=np_paypal_donation_nonce_field]").value;

                                    fetch("' . esc_url(admin_url('admin-ajax.php')) . '", {
                                        method: "POST",
                                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                                        body: new URLSearchParams({
                                            action: "np_log_paypal_donation",
                                            email: email,
                                            amount: amount,
                                            frequency: frequency,
                                            np_paypal_donation_nonce_field: nonce
                                        })
                                    });
                                    window.location.href = window.location.href.split("?")[0] + "?paypal_success=1";
                                });
                            }
                        }).render("#paypal-button-container");
                    }
                });
            </script>';
        }

        echo '</form>';
        echo '</div>';
        return ob_get_clean();
    });

    // Log PayPal donations in DB (AJAX)
    add_action('wp_ajax_np_log_paypal_donation', 'np_handle_paypal_donation');
    add_action('wp_ajax_nopriv_np_log_paypal_donation', 'np_handle_paypal_donation');

    function np_handle_paypal_donation() {
        $nonce = isset($_POST['np_paypal_donation_nonce_field']) ? sanitize_text_field(wp_unslash($_POST['np_paypal_donation_nonce_field'])) : '';
        if (!wp_verify_nonce($nonce, 'np_paypal_donation_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            wp_die();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'np_donations';

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
    add_action('np_render_paypal_settings_section', function () {
        $method = get_option('np_paypal_method', 'sdk');
        $mode   = get_option('np_paypal_mode', 'live');

        echo '<div style="margin-top:20px;">';
        echo '<h3>' . esc_html__('PayPal Settings', 'nonprofit-manager') . '</h3><table class="form-table">';

        echo '<tr><th>' . esc_html__('Integration Method', 'nonprofit-manager') . '</th><td>';
        echo '<label><input type="radio" name="np_paypal_method" value="email" ' . checked($method, 'email', false) . '> Email Link</label><br>';
        echo '<label><input type="radio" name="np_paypal_method" value="sdk" ' . checked($method, 'sdk', false) . '> Smart Button</label>';
        echo '</td></tr>';

        echo '<tr data-method="email"><th>Email for Donations</th><td><input type="email" name="np_paypal_email" value="' . esc_attr(get_option('np_paypal_email')) . '" class="regular-text" /></td></tr>';
        echo '<tr data-method="sdk"><th>Client ID</th><td><input type="text" name="np_paypal_client_id" value="' . esc_attr(get_option('np_paypal_client_id')) . '" class="regular-text" /></td></tr>';
        echo '<tr data-method="sdk"><th>Secret</th><td><input type="text" name="np_paypal_secret" value="' . esc_attr(get_option('np_paypal_secret')) . '" class="regular-text" /></td></tr>';

        echo '<tr data-method="sdk"><th>Environment</th><td>';
        echo '<label><input type="radio" name="np_paypal_mode" value="live" ' . checked($mode, 'live', false) . '> Live</label><br>';
        echo '<label><input type="radio" name="np_paypal_mode" value="sandbox" ' . checked($mode, 'sandbox', false) . '> Sandbox</label>';
        echo '</td></tr>';

        echo '<tr><th>Minimum</th><td><input type="number" step="0.01" name="np_paypal_minimum" value="' . esc_attr(get_option('np_paypal_minimum', 1)) . '" class="small-text" /> USD</td></tr>';
        echo '</table></div>';
    });
}
