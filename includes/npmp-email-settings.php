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
    echo '<td><input type="text" name="npmp_email_from_name" value="' . esc_attr($from_name) . '" class="regular-text"></td></tr>';
    
    echo '<tr><th>' . esc_html__('From Email', 'nonprofit-manager') . '</th>';
    echo '<td><input type="email" name="npmp_email_from_email" value="' . esc_attr($from_email) . '" class="regular-text"></td></tr>';
    
    echo '<tr><th>' . esc_html__('Delivery Method', 'nonprofit-manager') . '</th><td>';
    echo '<label><input type="radio" name="npmp_email_method" value="wp_mail" ' . checked($method, 'wp_mail', false) . '> ' . esc_html__('WordPress Default (wp_mail)', 'nonprofit-manager') . '</label><br>';
    echo '<label><input type="radio" name="npmp_email_method" value="smtp" ' . checked($method, 'smtp', false) . '> ' . esc_html__('SMTP', 'nonprofit-manager') . '</label><br>';
    echo '</td></tr>';
    
    // SMTP Settings section
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('SMTP Host', 'nonprofit-manager') . '</th>';
    echo '<td><input type="text" name="npmp_smtp_host" value="' . esc_attr(get_option('npmp_smtp_host', '')) . '" class="regular-text"></td></tr>';
    
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('SMTP Port', 'nonprofit-manager') . '</th>';
    echo '<td><input type="number" name="npmp_smtp_port" value="' . esc_attr(get_option('npmp_smtp_port', 587)) . '" class="small-text"></td></tr>';
    
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('Encryption', 'nonprofit-manager') . '</th><td>';
    echo '<label><input type="radio" name="npmp_smtp_encryption" value="none" ' . checked(get_option('npmp_smtp_encryption', 'tls'), 'none', false) . '> ' . esc_html__('None', 'nonprofit-manager') . '</label><br>';
    echo '<label><input type="radio" name="npmp_smtp_encryption" value="ssl" ' . checked(get_option('npmp_smtp_encryption', 'tls'), 'ssl', false) . '> ' . esc_html__('SSL', 'nonprofit-manager') . '</label><br>';
    echo '<label><input type="radio" name="npmp_smtp_encryption" value="tls" ' . checked(get_option('npmp_smtp_encryption', 'tls'), 'tls', false) . '> ' . esc_html__('TLS', 'nonprofit-manager') . '</label>';
    echo '</td></tr>';
    
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('Authentication', 'nonprofit-manager') . '</th>';
    echo '<td><input type="checkbox" name="npmp_smtp_auth" value="1" ' . checked(get_option('npmp_smtp_auth', 1), 1, false) . '> ' . esc_html__('Use SMTP authentication', 'nonprofit-manager') . '</td></tr>';
    
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('Username', 'nonprofit-manager') . '</th>';
    echo '<td><input type="text" name="npmp_smtp_username" value="' . esc_attr(get_option('npmp_smtp_username', '')) . '" class="regular-text"></td></tr>';
    
    echo '<tr class="smtp-settings' . ($method !== 'smtp' ? ' hidden' : '') . '"><th>' . esc_html__('Password', 'nonprofit-manager') . '</th>';
    echo '<td><input type="password" name="npmp_smtp_password" value="' . esc_attr(get_option('npmp_smtp_password', '')) . '" class="regular-text"></td></tr>';
    
    echo '</table>';
    
    // Add JavaScript for toggling SMTP settings
    echo '<script>
        jQuery(document).ready(function($) {
            $("input[name=\'npmp_email_method\']").change(function() {
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
    
    if (isset($_POST['npmp_test_email_nonce']) && 
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['npmp_test_email_nonce'])), 'npmp_test_email')) {
        
        $to = sanitize_email(wp_unslash($_POST['npmp_test_email'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['npmp_test_subject'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['npmp_test_message'] ?? ''));
        
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
            $from_name = get_option('npmp_email_from_name', get_bloginfo('name'));
            $from_email = get_option('npmp_email_from_email', get_bloginfo('admin_email'));
            
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $from_name . ' <' . $from_email . '>'
            ];
            
            // Send the email
            add_action('wp_mail_failed', 'npmp_email_error_handler');
            
            // Handler function for email errors
            if (!function_exists('npmp_email_error_handler')) {
                function npmp_email_error_handler($wp_error) {
                    update_option('npmp_email_last_error', $wp_error);
                }
            }
            
            // Delete previous errors
            delete_option('npmp_email_last_error');
            
            // Send the test email
            $success = wp_mail($to, $subject, nl2br($message), $headers);
            
            if ($success) {
                echo '<div class="updated"><p>' . esc_html__('✅ Test email sent successfully!', 'nonprofit-manager') . '</p></div>';
            } else {
                echo '<div class="error"><p>' . esc_html__('❌ Failed to send test email.', 'nonprofit-manager') . '</p>';
                
                // Get error details if available
                $error = get_option('npmp_email_last_error');
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
    wp_nonce_field('npmp_test_email', 'npmp_test_email_nonce');
    
    echo '<table class="form-table">';
    echo '<tr><th><label for="npmp_test_email">' . esc_html__('Recipient Email', 'nonprofit-manager') . '</label></th>';
    echo '<td><input type="email" name="npmp_test_email" id="npmp_test_email" value="' . esc_attr(wp_get_current_user()->user_email) . '" class="regular-text" required></td></tr>';
    
    echo '<tr><th><label for="npmp_test_subject">' . esc_html__('Subject', 'nonprofit-manager') . '</label></th>';
    echo '<td><input type="text" name="npmp_test_subject" id="npmp_test_subject" value="' . esc_attr__('Test Email from Nonprofit Manager', 'nonprofit-manager') . '" class="regular-text" required></td></tr>';
    
    echo '<tr><th><label for="npmp_test_message">' . esc_html__('Message', 'nonprofit-manager') . '</label></th>';
    echo '<td><textarea name="npmp_test_message" id="npmp_test_message" rows="5" class="large-text" required>' . esc_textarea(__("This is a test email sent from the Nonprofit Manager plugin.\n\nIf you're receiving this, your email settings are working correctly.", 'nonprofit-manager')) . '</textarea></td></tr>';
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

// Create a class to handle member management
class NPMP_Member_Manager {
    private static $instance = null;
    
    // Get the singleton instance
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // Update a member
    public function update_member($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'npmp_members';
        
        $result = $wpdb->update(
            $table,
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'membership_level' => $data['membership_level'],
                'status' => $data['status']
            ],
            ['id' => $id]
        );
        
        // Clear cache
        $this->clear_member_cache($data['email']);
        wp_cache_delete('npmp_member_' . $id, 'npmp_members');
        
        return $result;
    }
    
    // Get a member by ID
    public function get_member_by_id($id) {
        global $wpdb;
        
        // Try to get from cache
        $cache_key = 'npmp_member_' . $id;
        $member = wp_cache_get($cache_key, 'npmp_members');
        
        if ($member === false) {
            $member = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}npmp_members WHERE id = %d",
                $id
            ));
            
            if ($member) {
                wp_cache_set($cache_key, $member, 'npmp_members', 300);
            }
        }
        
        return $member;
    }
    
    // Delete a member
    public function delete_member($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'npmp_members';
        
        // Get email before deleting to clear cache
        $member = $this->get_member_by_id($id);
        if ($member && !empty($member->email)) {
            $this->clear_member_cache($member->email);
        }
        
        // Clear member ID cache
        wp_cache_delete('npmp_member_' . $id, 'npmp_members');
        
        return $wpdb->delete($table, ['id' => $id]);
    }
    
    // Delete multiple members
    public function delete_members($ids) {
        global $wpdb;
        
        $result = 0;
        foreach ($ids as $id) {
            $result += $this->delete_member($id);
        }
        
        // Clear all members cache
        $this->clear_all_members_cache();
        
        return $result;
    }
    
    // Check if email exists
    public function email_exists($email) {
        global $wpdb;
        
        // Try to get from cache
        $cache_key = "npmp_member_email_$email";
        $existing = wp_cache_get($cache_key, 'npmp_members');
        
        if ($existing === false) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_members WHERE email = %s",
                $email
            ));
            wp_cache_set($cache_key, $existing, 'npmp_members', 300);
        }
        
        return $existing;
    }
    
    // Add a new member
    public function add_member($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'npmp_members';
        
        $result = $wpdb->insert(
            $table,
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'membership_level' => $data['membership_level'] ?? '',
                'status' => $data['status'] ?? 'subscribed',
                'created_at' => current_time('mysql')
            ]
        );
        
        // Clear cache
        $this->clear_member_cache($data['email']);
        $this->clear_all_members_cache();
        
        return $result;
    }
    
    // Update member status
    public function update_status($email, $status) {
        global $wpdb;
        $table = $wpdb->prefix . 'npmp_members';
        
        $result = $wpdb->update(
            $table,
            ['status' => $status],
            ['email' => $email]
        );
        
        // Clear cache
        $this->clear_member_cache($email);
        $this->clear_all_members_cache();
        
        return $result;
    }
    
    // Get all members
    public function get_all_members() {
        global $wpdb;
        
        // Try to get from cache
        $cache_key = 'npmp_members_all';
        $members = wp_cache_get($cache_key, 'npmp_members');
        
        if ($members === false) {
            $members = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}npmp_members ORDER BY created_at DESC"
            );
            wp_cache_set($cache_key, $members, 'npmp_members', 300);
        }
        
        return $members;
    }
    
    // Clear member cache
    private function clear_member_cache($email) {
        wp_cache_delete("npmp_member_email_$email", 'npmp_members');
    }
    
    // Clear all members cache
    private function clear_all_members_cache() {
        wp_cache_delete('npmp_members_all', 'npmp_members');
    }
}

if (!function_exists('npmp_render_members_page')) {
    function npmp_render_members_page() {
        // Check if user has permission to access this page
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'nonprofit-manager'));
        }
        global $wpdb;
        $table = $wpdb->prefix . 'npmp_members';

        // Handle update
        if (!empty($_POST['npmp_save_member']) && isset($_POST['npmp_members_nonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['npmp_members_nonce'])), 'npmp_manage_members')) {
            
            $id = isset($_POST['member_id']) ? intval($_POST['member_id']) : 0;
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            $membership_level = sanitize_text_field(wp_unslash($_POST['membership_level'] ?? ''));
            $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));

            // Use member manager instead of direct database call
            $member_manager = NPMP_Member_Manager::get_instance();
            $member_manager->update_member($id, [
                'name' => $name,
                'email' => $email,
                'membership_level' => $membership_level,
                'status' => $status
            ]);

            echo '<div class="updated"><p>' . esc_html__('Member updated.', 'nonprofit-manager') . '</p></div>';
            return;
        }

        // Show edit form
        if (isset($_GET['action'], $_GET['id']) && sanitize_text_field(wp_unslash($_GET['action'])) === 'edit' && current_user_can('manage_options')) {
            $id = intval($_GET['id']);
            // Use member manager instead of direct database call
            $member_manager = NPMP_Member_Manager::get_instance();
            $member = $member_manager->get_member_by_id($id);
            if (!$member) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Member not found.', 'nonprofit-manager') . '</p></div>';
                return;
            }

            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Edit Member', 'nonprofit-manager') . '</h1>';
            echo '<form method="post">';
            wp_nonce_field('npmp_manage_members', 'npmp_members_nonce');
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
            submit_button(__('Save Changes', 'nonprofit-manager'), 'primary', 'npmp_save_member');
            echo '</form>';
            echo '</div>';
            return;
        }

        // Handle deletion
        if (isset($_GET['action'], $_GET['id']) && sanitize_text_field(wp_unslash($_GET['action'])) === 'delete' && 
            check_admin_referer('npmp_manage_members_delete_' . intval($_GET['id']))) {
            // Use member manager instead of direct database call
            $member_manager = NPMP_Member_Manager::get_instance();
            $member_manager->delete_member(intval($_GET['id']));
            echo '<div class="updated"><p>' . esc_html__('Member deleted.', 'nonprofit-manager') . '</p></div>';
        }

        // Handle bulk deletion
        if (!empty($_POST['bulk_delete']) && isset($_POST['npmp_members_nonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['npmp_members_nonce'])), 'npmp_manage_members')) {
            $user_ids = array_map('intval', (array) wp_unslash($_POST['user_ids'] ?? []));
            // Use member manager instead of direct database call
            $member_manager = NPMP_Member_Manager::get_instance();
            $member_manager->delete_members($user_ids);
            echo '<div class="updated"><p>' . esc_html__('Selected members deleted.', 'nonprofit-manager') . '</p></div>';
        }

        // Handle manual addition and form-based signup
        $name = sanitize_text_field(wp_unslash($_POST['manual_name'] ?? ($_POST['npmp_name'] ?? '')));
        $email = sanitize_email(wp_unslash($_POST['manual_email'] ?? ($_POST['npmp_email'] ?? '')));
        $form_submitted = (isset($_POST['manual_add']) || isset($_POST['npmp_subscribe'])) && isset($_POST['npmp_members_nonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['npmp_members_nonce'])), 'npmp_manage_members');

        if ($form_submitted && $name && $email) {
            // Use member manager instead of direct database call
            $member_manager = NPMP_Member_Manager::get_instance();
            $existing = $member_manager->email_exists($email);

            if (!$existing) {
                // Use member manager instead of direct database call
                $member_manager->add_member([
                    'name' => $name,
                    'email' => $email,
                    'membership_level' => '',
                    'status' => 'subscribed'
                ]);
                echo '<div class="updated"><p>' . esc_html__('Member added.', 'nonprofit-manager') . '</p></div>';
            } elseif (!empty($_POST['manual_add'])) {
                echo '<div class="notice notice-warning"><p>' . esc_html__('This email address is already subscribed.', 'nonprofit-manager') . '</p></div>';
            }
        }

        // Handle unsubscribe
        if (!empty($_POST['npmp_unsubscribe']) && isset($_POST['npmp_members_nonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['npmp_members_nonce'])), 'npmp_manage_members')) {
            $email = sanitize_email(wp_unslash($_POST['npmp_email'] ?? ''));
            // Use member manager instead of direct database call
            $member_manager = NPMP_Member_Manager::get_instance();
            $member_manager->update_status($email, 'unsubscribed');
            echo '<div class="updated"><p>' . esc_html__('You have been unsubscribed.', 'nonprofit-manager') . '</p></div>';
        }

        // Use member manager instead of direct database call
        $member_manager = NPMP_Member_Manager::get_instance();
        $members = $member_manager->get_all_members();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Members & Subscribers', 'nonprofit-manager') . '</h1>';

        echo '<h2>' . esc_html__('Manually Add Member', 'nonprofit-manager') . '</h2>';
        echo '<form method="post" style="margin-bottom: 20px;">';
        wp_nonce_field('npmp_manage_members', 'npmp_members_nonce');
        echo '<input type="text" name="manual_name" placeholder="' . esc_attr__('Name', 'nonprofit-manager') . '" required style="margin-right:10px;">';
        echo '<input type="email" name="manual_email" placeholder="' . esc_attr__('Email', 'nonprofit-manager') . '" required style="margin-right:10px;">';
        echo '<input type="submit" name="manual_add" class="button button-primary" value="' . esc_attr__('Add Member', 'nonprofit-manager') . '">';
        echo '</form>';

        echo '<form method="post">';
        wp_nonce_field('npmp_manage_members', 'npmp_members_nonce');
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
            echo '<td><a href="' . esc_url(add_query_arg(['page' => 'npmp_members', 'action' => 'edit', 'id' => $member->id])) . '">' . esc_html__('Edit', 'nonprofit-manager') . '</a> | ';
            echo '<a href="' . esc_url(wp_nonce_url(add_query_arg(['page' => 'npmp_members', 'action' => 'delete', 'id' => $member->id]), 'npmp_manage_members_delete_' . $member->id)) . '" onclick="return confirm(\'Are you sure?\')">' . esc_html__('Delete', 'nonprofit-manager') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><input type="submit" name="bulk_delete" class="button" value="' . esc_attr__('Delete Selected', 'nonprofit-manager') . '"></p>';
        echo '</form>';
        echo '</div>';
    }
}