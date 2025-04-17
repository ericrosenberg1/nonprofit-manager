<?php
// includes/npmp-email-settings.php

if (!defined('ABSPATH')) exit;

// Email Settings page
function npmp_render_email_settings_page() {
    // Check if user has permission to access this page
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'nonprofit-manager'));
    }
    
    // Handle email settings form submission with nonce verification
    if (isset($_POST['npmp_email_settings_nonce']) && 
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['npmp_email_settings_nonce'])), 'npmp_email_settings')) {
        
        // Save email settings
        $method = sanitize_text_field(wp_unslash($_POST['npmp_email_method'] ?? 'wp_mail'));
        $from_name = sanitize_text_field(wp_unslash($_POST['npmp_email_from_name'] ?? ''));
        $from_email = sanitize_email(wp_unslash($_POST['npmp_email_from_email'] ?? ''));
        
        update_option('npmp_email_method', $method);
        update_option('npmp_email_from_name', $from_name);
        update_option('npmp_email_from_email', $from_email);
        
        // Method-specific settings
        if ($method === 'smtp') {
            update_option('npmp_smtp_host', sanitize_text_field(wp_unslash($_POST['npmp_smtp_host'] ?? '')));
            update_option('npmp_smtp_port', intval($_POST['npmp_smtp_port'] ?? 587));
            update_option('npmp_smtp_encryption', sanitize_text_field(wp_unslash($_POST['npmp_smtp_encryption'] ?? 'tls')));
            update_option('npmp_smtp_auth', isset($_POST['npmp_smtp_auth']) ? 1 : 0);
            update_option('npmp_smtp_username', sanitize_text_field(wp_unslash($_POST['npmp_smtp_username'] ?? '')));
            update_option('npmp_smtp_password', sanitize_text_field(wp_unslash($_POST['npmp_smtp_password'] ?? '')));
        }
        
        echo '<div class="updated"><p>' . esc_html__('Email settings saved.', 'nonprofit-manager') . '</p></div>';
    }
    
    // Get current settings
    $method = get_option('npmp_email_method', 'wp_mail');
    $from_name = get_option('npmp_email_from_name', get_bloginfo('name'));
    $from_email = get_option('npmp_email_from_email', get_bloginfo('admin_email'));
    
    // Render settings page
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Email Settings', 'nonprofit-manager') . '</h1>';
    echo '<form method="post" action="">';
    wp_nonce_field('npmp_email_settings', 'npmp_email_settings_nonce');
    
    echo '<table class="form-table">';
    echo '<tr><th>' . esc_html__('From Name', 'nonprofit-manager') . '</th>';
    echo '<td><input type="text" name="np_email_from_name" value="' . esc_attr($from_name) . '" class="regular-text"></td></tr>';
    
    echo '<tr><th>' . esc_html__('From Email', 'nonprofit-manager') . '</th>';
    echo '<td><input type="email" name="np_email_from_email" value="' . esc_attr($from_email) . '" class="regular-text"></td></tr>';
    
    echo '<tr><th>' . esc_html__('Delivery Method', 'nonprofit-manager') . '</th><td>';
    echo '<label><input type="radio" name="np_email_method" value="wp_mail" ' . checked($method, 'wp_mail', false) . '> ' . esc_html__('WordPress Default (wp_mail)', 'nonprofit-manager') . '</label><br>';
    echo '<label><input type="radio" name="np_email_method" value="smtp" ' . checked($method, 'smtp', false) . '> ' . esc_html__('SMTP', 'nonprofit-manager') . '</label><br>';
    echo '</td></tr>';
    
    // SMTP Settings section
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('SMTP Host', 'nonprofit-manager') . '</th>';
    echo '<td><input type="text" name="np_smtp_host" value="' . esc_attr(get_option('np_smtp_host', '')) . '" class="regular-text"></td></tr>';
    
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('SMTP Port', 'nonprofit-manager') . '</th>';
    echo '<td><input type="number" name="np_smtp_port" value="' . esc_attr(get_option('np_smtp_port', 587)) . '" class="small-text"></td></tr>';
    
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('Encryption', 'nonprofit-manager') . '</th><td>';
    echo '<label><input type="radio" name="np_smtp_encryption" value="none" ' . checked(get_option('np_smtp_encryption', 'tls'), 'none', false) . '> ' . esc_html__('None', 'nonprofit-manager') . '</label><br>';
    echo '<label><input type="radio" name="np_smtp_encryption" value="ssl" ' . checked(get_option('np_smtp_encryption', 'tls'), 'ssl', false) . '> ' . esc_html__('SSL', 'nonprofit-manager') . '</label><br>';
    echo '<label><input type="radio" name="np_smtp_encryption" value="tls" ' . checked(get_option('np_smtp_encryption', 'tls'), 'tls', false) . '> ' . esc_html__('TLS', 'nonprofit-manager') . '</label>';
    echo '</td></tr>';
    
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('Authentication', 'nonprofit-manager') . '</th>';
    echo '<td><input type="checkbox" name="np_smtp_auth" value="1" ' . checked(get_option('np_smtp_auth', 1), 1, false) . '> ' . esc_html__('Use SMTP authentication', 'nonprofit-manager') . '</td></tr>';
    
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('Username', 'nonprofit-manager') . '</th>';
    echo '<td><input type="text" name="np_smtp_username" value="' . esc_attr(get_option('np_smtp_username', '')) . '" class="regular-text"></td></tr>';
    
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('Password', 'nonprofit-manager') . '</th>';
    echo '<td><input type="password" name="np_smtp_password" value="' . esc_attr(get_option('np_smtp_password', '')) . '" class="regular-text"></td></tr>';
    
    echo '</table>';
    
    // Add JavaScript for toggling SMTP settings
    echo '<script>
        jQuery(document).ready(function($) {
            $("input[name=\'np_email_method\']").change(function() {
                if ($(this).val() === "smtp") {
                    $(".smtp-settings").show();
                } else {
                    $(".smtp-settings").hide();
                }
            });
        });
    </script>';
    
    submit_button(__('Save Settings', 'nonprofit-manager'));
    echo '</form>';
    
    // Test Email Form
    echo '<hr>';
    echo '<h2>' . esc_html__('Send Test Email', 'nonprofit-manager') . '</h2>';
    echo '<p>' . esc_html__('Use this form to test your email configuration. It will use your selected email method (WordPress default or SMTP).', 'nonprofit-manager') . '</p>';
    
    if (isset($_POST['np_test_email_nonce']) && 
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['np_test_email_nonce'])), 'np_test_email')) {
        
        $to = sanitize_email(wp_unslash($_POST['np_test_email'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['np_test_subject'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['np_test_message'] ?? ''));
        
        if ($to && $subject && $message) {
            // Set up debugging
            global $phpmailer;
            
            // Save old error state
            $old_errors = [];
            if (isset($GLOBALS['EZSQL_ERROR'])) {
                $old_errors = $GLOBALS['EZSQL_ERROR'];
                $GLOBALS['EZSQL_ERROR'] = [];
            }
            
            // Define email headers based on settings
            $from_name = get_option('np_email_from_name', get_bloginfo('name'));
            $from_email = get_option('np_email_from_email', get_bloginfo('admin_email'));
            
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $from_name . ' <' . $from_email . '>'
            ];
            
            // Send the email
            add_action('wp_mail_failed', 'np_email_error_handler');
            
            // Handler function for email errors
            if (!function_exists('np_email_error_handler')) {
                function np_email_error_handler($wp_error) {
                    update_option('np_email_last_error', $wp_error);
                }
            }
            
            // Delete previous errors
            delete_option('np_email_last_error');
            
            // Send the test email
            $success = wp_mail($to, $subject, nl2br($message), $headers);
            
            if ($success) {
                echo '<div class="updated"><p>' . esc_html__('✅ Test email sent successfully!', 'nonprofit-manager') . '</p></div>';
            } else {
                echo '<div class="error"><p>' . esc_html__('❌ Failed to send test email.', 'nonprofit-manager') . '</p>';
                
                // Get error details if available
                $error = get_option('np_email_last_error');
                if ($error) {
                    echo '<h3>' . esc_html__('Error Details:', 'nonprofit-manager') . '</h3>';
                    echo '<pre style="background:#fff;border:1px solid #ccc;padding:10px;max-height:300px;overflow:auto;">';
                    if (is_wp_error($error)) {
                        echo esc_html($error->get_error_message());
                        
                        // Get error data for more details
                        $error_data = $error->get_error_data();
                        if ($error_data) {
                            echo "\n\n" . esc_html__('Additional Error Data:', 'nonprofit-manager') . "\n";
                            echo esc_html(wp_json_encode($error_data, JSON_PRETTY_PRINT));
                        }
                    } else {
                        echo esc_html(wp_json_encode($error, JSON_PRETTY_PRINT));
                    }
                    echo '</pre>';
                    
                    // SMTP-specific troubleshooting
                    if ($method === 'smtp') {
                        echo '<h3>' . esc_html__('SMTP Troubleshooting:', 'nonprofit-manager') . '</h3>';
                        echo '<ul>';
                        echo '<li>' . esc_html__('Verify your SMTP host and port settings', 'nonprofit-manager') . '</li>';
                        echo '<li>' . esc_html__('Check if your server allows outgoing connections to your SMTP server', 'nonprofit-manager') . '</li>';
                        echo '<li>' . esc_html__('Make sure your SMTP credentials are correct', 'nonprofit-manager') . '</li>';
                        echo '<li>' . esc_html__('Try using a different encryption method (TLS/SSL)', 'nonprofit-manager') . '</li>';
                        echo '</ul>';
                    }
                } else {
                    echo '<p>' . esc_html__('No detailed error information available.', 'nonprofit-manager') . '</p>';
                }
                
                echo '</div>';
            }
            
            // Restore old error state
            if (isset($GLOBALS['EZSQL_ERROR'])) {
                $GLOBALS['EZSQL_ERROR'] = $old_errors;
            }
        }
    }
    
    echo '<form method="post" action="">';
    wp_nonce_field('np_test_email', 'np_test_email_nonce');
    
    echo '<table class="form-table">';
    echo '<tr><th><label for="np_test_email">' . esc_html__('Recipient Email', 'nonprofit-manager') . '</label></th>';
    echo '<td><input type="email" name="np_test_email" id="np_test_email" value="' . esc_attr(wp_get_current_user()->user_email) . '" class="regular-text" required></td></tr>';
    
    echo '<tr><th><label for="np_test_subject">' . esc_html__('Subject', 'nonprofit-manager') . '</label></th>';
    echo '<td><input type="text" name="np_test_subject" id="np_test_subject" value="' . esc_attr__('Test Email from Nonprofit Manager', 'nonprofit-manager') . '" class="regular-text" required></td></tr>';
    
    echo '<tr><th><label for="np_test_message">' . esc_html__('Message', 'nonprofit-manager') . '</label></th>';
    echo '<td><textarea name="np_test_message" id="np_test_message" rows="5" class="large-text" required>' . esc_textarea(__("This is a test email sent from the Nonprofit Manager plugin.\n\nIf you're receiving this, your email settings are working correctly.", 'nonprofit-manager')) . '</textarea></td></tr>';
    echo '</table>';
    
    submit_button(__('Send Test Email', 'nonprofit-manager'), 'secondary');
    echo '</form>';
    
    echo '</div>';
}

// The Email Delivery page is implemented in np-email-delivery.php
// This is just a placeholder in case the function is called directly
if (!function_exists('npmp_render_email_delivery_page')) {
    function npmp_render_email_delivery_page() {
        // Function is implemented in includes/np-email-delivery.php
    }
}

if (!function_exists('npmp_render_members_page')) {
    function npmp_render_members_page() {
        // Check if user has permission to access this page
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'nonprofit-manager'));
        }
        global $wpdb;
        $table = $wpdb->prefix . 'np_members';

        // Handle update
        if (!empty($_POST['np_save_member']) && isset($_POST['np_members_nonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['np_members_nonce'])), 'np_manage_members')) {
            
            $id = isset($_POST['member_id']) ? intval($_POST['member_id']) : 0;
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            $membership_level = sanitize_text_field(wp_unslash($_POST['membership_level'] ?? ''));
            $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));

            $wpdb->update($table, [
                'name' => $name,
                'email' => $email,
                'membership_level' => $membership_level,
                'status' => $status
            ], ['id' => $id]);

            echo '<div class="updated"><p>' . esc_html__('Member updated.', 'nonprofit-manager') . '</p></div>';
            return;
        }

        // Show edit form
        if (isset($_GET['action'], $_GET['id']) && sanitize_text_field(wp_unslash($_GET['action'])) === 'edit' && current_user_can('manage_options')) {
            $id = intval($_GET['id']);
            $table_name = $wpdb->prefix . 'np_members';
            $member = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table_name, $id));
            if (!$member) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Member not found.', 'nonprofit-manager') . '</p></div>';
                return;
            }

            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Edit Member', 'nonprofit-manager') . '</h1>';
            echo '<form method="post">';
            wp_nonce_field('np_manage_members', 'np_members_nonce');
            echo '<input type="hidden" name="member_id" value="' . esc_attr($member->id) . '">';
            echo '<table class="form-table">';
            echo '<tr><th>' . esc_html__('Name', 'nonprofit-manager') . '</th><td><input type="text" name="name" value="' . esc_attr($member->name) . '" class="regular-text" required></td></tr>';
            echo '<tr><th>' . esc_html__('Email', 'nonprofit-manager') . '</th><td><input type="email" name="email" value="' . esc_attr($member->email) . '" class="regular-text" required></td></tr>';
            echo '<tr><th>' . esc_html__('Membership Level', 'nonprofit-manager') . '</th><td><input type="text" name="membership_level" value="' . esc_attr($member->membership_level) . '" class="regular-text"></td></tr>';
            echo '<tr><th>' . esc_html__('Status', 'nonprofit-manager') . '</th><td><select name="status">';
            foreach (['subscribed', 'pending', 'unsubscribed'] as $status_option) {
                echo '<option value="' . esc_attr($status_option) . '"' . selected($member->status, $status_option, false) . '>' . esc_html(ucfirst($status_option)) . '</option>';
            }
            echo '</select></td></tr>';
            echo '</table>';
            submit_button(__('Save Changes', 'nonprofit-manager'), 'primary', 'np_save_member');
            echo '</form>';
            echo '</div>';
            return;
        }

        // Handle deletion
        if (isset($_GET['action'], $_GET['id']) && sanitize_text_field(wp_unslash($_GET['action'])) === 'delete' && 
            check_admin_referer('np_manage_members_delete_' . intval($_GET['id']))) {
            $wpdb->delete($table, ['id' => intval($_GET['id'])]);
            echo '<div class="updated"><p>' . esc_html__('Member deleted.', 'nonprofit-manager') . '</p></div>';
        }

        // Handle bulk deletion
        if (!empty($_POST['bulk_delete']) && isset($_POST['np_members_nonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['np_members_nonce'])), 'np_manage_members')) {
            $user_ids = array_map('intval', (array) wp_unslash($_POST['user_ids'] ?? []));
            foreach ($user_ids as $id) {
                $wpdb->delete($table, ['id' => $id]);
            }
            echo '<div class="updated"><p>' . esc_html__('Selected members deleted.', 'nonprofit-manager') . '</p></div>';
        }

        // Handle manual addition and form-based signup
        $name = sanitize_text_field(wp_unslash($_POST['manual_name'] ?? ($_POST['np_name'] ?? '')));
        $email = sanitize_email(wp_unslash($_POST['manual_email'] ?? ($_POST['np_email'] ?? '')));
        $form_submitted = (isset($_POST['manual_add']) || isset($_POST['np_subscribe'])) && isset($_POST['np_members_nonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['np_members_nonce'])), 'np_manage_members');

        if ($form_submitted && $name && $email) {
            $existing = wp_cache_get("np_member_email_$email", 'np_members');
            if ($existing === false) {
                $table_name = $wpdb->prefix . 'np_members';
                $existing = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE email = %s", $table_name, $email));
                wp_cache_set("np_member_email_$email", $existing, 'np_members', 300);
            }

            if (!$existing) {
                $wpdb->insert($table, [
                    'name' => $name,
                    'email' => $email,
                    'membership_level' => '',
                    'status' => 'subscribed',
                    'created_at' => current_time('mysql')
                ]);
                wp_cache_delete("np_member_email_$email", 'np_members');
                echo '<div class="updated"><p>' . esc_html__('Member added.', 'nonprofit-manager') . '</p></div>';
            } elseif (!empty($_POST['manual_add'])) {
                echo '<div class="notice notice-warning"><p>' . esc_html__('This email address is already subscribed.', 'nonprofit-manager') . '</p></div>';
            }
        }

        // Handle unsubscribe
        if (!empty($_POST['np_unsubscribe']) && isset($_POST['np_members_nonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['np_members_nonce'])), 'np_manage_members')) {
            $email = sanitize_email(wp_unslash($_POST['np_email'] ?? ''));
            $wpdb->update($table, ['status' => 'unsubscribed'], ['email' => $email]);
            wp_cache_delete("np_member_email_$email", 'np_members');
            echo '<div class="updated"><p>' . esc_html__('You have been unsubscribed.', 'nonprofit-manager') . '</p></div>';
        }

        $members = wp_cache_get('np_members_all', 'np_members');
        if ($members === false) {
            $table_name = $wpdb->prefix . 'np_members';
            $members = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i ORDER BY created_at DESC", $table_name));
            wp_cache_set('np_members_all', $members, 'np_members', 300);
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Members & Subscribers', 'nonprofit-manager') . '</h1>';

        echo '<h2>' . esc_html__('Manually Add Member', 'nonprofit-manager') . '</h2>';
        echo '<form method="post" style="margin-bottom: 20px;">';
        wp_nonce_field('np_manage_members', 'np_members_nonce');
        echo '<input type="text" name="manual_name" placeholder="' . esc_attr__('Name', 'nonprofit-manager') . '" required style="margin-right:10px;">';
        echo '<input type="email" name="manual_email" placeholder="' . esc_attr__('Email', 'nonprofit-manager') . '" required style="margin-right:10px;">';
        echo '<input type="submit" name="manual_add" class="button button-primary" value="' . esc_attr__('Add Member', 'nonprofit-manager') . '">';
        echo '</form>';

        echo '<form method="post">';
        wp_nonce_field('np_manage_members', 'np_members_nonce');
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<td class="check-column"><input type="checkbox" id="bulk-select-all"></td>';
        echo '<th>' . esc_html__('Name', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Membership Level', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Email Address', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Status', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Actions', 'nonprofit-manager') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($members as $member) {
            echo '<tr>';
            echo '<th class="check-column"><input type="checkbox" name="user_ids[]" value="' . esc_attr($member->id) . '"></th>';
            echo '<td>' . esc_html($member->name) . '</td>';
            echo '<td>' . esc_html($member->membership_level) . '</td>';
            echo '<td>' . esc_html($member->email) . '</td>';
            echo '<td>' . esc_html($member->status) . '</td>';
            echo '<td><a href="' . esc_url(add_query_arg(['page' => 'np_members', 'action' => 'edit', 'id' => $member->id])) . '">' . esc_html__('Edit', 'nonprofit-manager') . '</a> | ';
            echo '<a href="' . esc_url(wp_nonce_url(add_query_arg(['page' => 'np_members', 'action' => 'delete', 'id' => $member->id]), 'np_manage_members_delete_' . $member->id)) . '" onclick="return confirm(\'Are you sure?\')">' . esc_html__('Delete', 'nonprofit-manager') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><input type="submit" name="bulk_delete" class="button" value="' . esc_attr__('Delete Selected', 'nonprofit-manager') . '"></p>';
        echo '</form>';
        echo '</div>';
    }
}