<?php
// includes/npmp-scripts.php

if (!defined('ABSPATH')) exit;

/**
 * Register and enqueue all frontend scripts for the plugin
 */
function npmp_register_frontend_scripts() {
    // PayPal SDK (if enabled)
    if (get_option('npmp_enable_paypal') && get_option('npmp_paypal_method', 'sdk') === 'sdk') {
        $client_id = esc_attr(get_option('npmp_paypal_client_id'));
        $mode = get_option('npmp_paypal_mode', 'live');
        $sdk_url = 'https://www.paypal.com/sdk/js?client-id=' . $client_id . '&currency=USD';
        if ($mode === 'sandbox') {
            $sdk_url .= '&debug=true';
        }

        wp_register_script('npmp-paypal-sdk', $sdk_url, [], '1.0.0', true);
        wp_enqueue_script('npmp-paypal-sdk');
    }
    
    // Register and enqueue frontend PayPal logic script
    wp_register_script(
        'npmp-donation-form',
        plugins_url('assets/js/donation-form.js', dirname(__FILE__)),
        ['jquery'],
        filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/js/donation-form.js'),
        true
    );
    
    // Localize script with necessary data
    $script_data = [
        'ajax_url' => admin_url('admin-ajax.php'),
        'min_amount' => floatval(get_option('npmp_paypal_minimum', 1))
    ];
    wp_localize_script('npmp-donation-form', 'npmpDonationData', $script_data);
    
    // Only enqueue on necessary pages
    if (is_page(get_option('npmp_donation_page_id')) || has_shortcode(get_the_content(), 'npmp_donation_form')) {
        wp_enqueue_script('npmp-donation-form');
    }
}
add_action('wp_enqueue_scripts', 'npmp_register_frontend_scripts');

/**
 * Register and enqueue admin scripts for the plugin
 */
function npmp_register_admin_scripts($hook) {
    // Block editor scripts
    if ('post.php' === $hook || 'post-new.php' === $hook) {
        $post_type = get_post_type();
        
        // Enqueue block scripts for newsletter editor
        if ($post_type === 'npmp_newsletter') {
            wp_enqueue_script(
                'npmp-blocks',
                plugins_url('assets/js/np-blocks.js', dirname(__FILE__)),
                ['wp-blocks', 'wp-element', 'wp-editor'],
                filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/js/np-blocks.js'),
                true
            );
            
            wp_enqueue_script(
                'npmp-newsletter-editor',
                plugins_url('includes/email-newsletter/assets/newsletter-editor.js', dirname(__FILE__)),
                ['wp-blocks', 'wp-i18n', 'wp-element', 'wp-editor'],
                filemtime(plugin_dir_path(dirname(__FILE__)) . 'includes/email-newsletter/assets/newsletter-editor.js'),
                true
            );
        }
    }
    
    // PayPal settings page script
    if (strpos($hook, 'npmp_payment_settings') !== false) {
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function ($) {
                const gatewayRadios = document.querySelectorAll(\"input[name='npmp_gateway']\");
                const paypalSection = document.getElementById(\"npmp-paypal-settings\");

                gatewayRadios.forEach(r => r.addEventListener(\"change\", function () {
                    if (this.value === \"paypal\") {
                        paypalSection.style.display = \"block\";
                    } else {
                        paypalSection.style.display = \"none\";
                    }
                }));

                const methodRadios = document.querySelectorAll(\"input[name='npmp_paypal_method']\");
                function togglePaypalMethodFields() {
                    const method = document.querySelector(\"input[name='npmp_paypal_method']:checked\")?.value || \"sdk\";
                    const emailRow = document.querySelector(\"tr[data-method='email']\");
                    const sdkRows  = document.querySelectorAll(\"tr[data-method='sdk']\");

                    if (emailRow) emailRow.style.display = method === \"email\" ? \"table-row\" : \"none\";
                    sdkRows.forEach(row => {
                        row.style.display = method === \"sdk\" ? \"table-row\" : \"none\";
                    });
                }

                methodRadios.forEach(r => r.addEventListener(\"change\", togglePaypalMethodFields));
                togglePaypalMethodFields();
            });
        ");
    }
    
    // Members page bulk select script
    if (strpos($hook, 'npmp_members') !== false) {
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function ($) {
                $('#bulk-select-all').on('click', function(e) {
                    const checkboxes = document.querySelectorAll(\"input[name='user_ids[]']\");
                    checkboxes.forEach(cb => cb.checked = e.target.checked);
                });
            });
        ");
    }
    
    // Handle redirects via JavaScript (convert to server-side redirects in future updates)
    if (strpos($hook, 'npmp_members') !== false && isset($_POST['npmp_save_member'])) {
        wp_add_inline_script('jquery', "window.location.href = '?page=npmp_members';", 'after');
    }
    if (strpos($hook, 'npmp_email_settings') !== false && isset($_POST['npmp_save_member'])) {
        wp_add_inline_script('jquery', "window.location.href = '?page=npmp_members';", 'after');
    }
}
add_action('admin_enqueue_scripts', 'npmp_register_admin_scripts');

/**
 * Register custom Gutenberg blocks
 */
function npmp_register_blocks() {
    // Register the email composer block
    if (function_exists('register_block_type')) {
        register_block_type('nonprofit-manager/email-composer', [
            'editor_script' => 'npmp-blocks',
        ]);
    }
}
add_action('init', 'npmp_register_blocks');
