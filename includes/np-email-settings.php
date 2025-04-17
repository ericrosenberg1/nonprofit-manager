<?php
// includes/np-members-settings.php

if (!defined('ABSPATH')) exit;

if (!function_exists('np_render_members_page')) {
    function np_render_members_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'np_members';

        // Handle update
        if (!empty($_POST['np_save_member']) && isset($_POST['np_members_nonce']) && wp_verify_nonce(wp_unslash($_POST['np_members_nonce']), 'np_manage_members')) {
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
            echo '<script>window.location.href = "?page=np_members";</script>';
            return;
        }

        // Show edit form
        if (isset($_GET['action'], $_GET['id']) && sanitize_text_field($_GET['action']) === 'edit') {
            $id = intval($_GET['id']);
            $query = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}np_members WHERE id = %d", $id);
            $member = $wpdb->get_row($query);
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
        if (isset($_GET['action'], $_GET['id']) && sanitize_text_field($_GET['action']) === 'delete' && check_admin_referer('np_manage_members_delete_' . intval($_GET['id']))) {
            $wpdb->delete($table, ['id' => intval($_GET['id'])]);
            echo '<div class="updated"><p>' . esc_html__('Member deleted.', 'nonprofit-manager') . '</p></div>';
        }

        // Handle bulk deletion
        if (!empty($_POST['bulk_delete']) && isset($_POST['np_members_nonce']) && wp_verify_nonce(wp_unslash($_POST['np_members_nonce']), 'np_manage_members')) {
            $user_ids = array_map('intval', (array) wp_unslash($_POST['user_ids'] ?? []));
            foreach ($user_ids as $id) {
                $wpdb->delete($table, ['id' => $id]);
            }
            echo '<div class="updated"><p>' . esc_html__('Selected members deleted.', 'nonprofit-manager') . '</p></div>';
        }

        // Handle manual addition and form-based signup
        $name = sanitize_text_field(wp_unslash($_POST['manual_name'] ?? ($_POST['np_name'] ?? '')));
        $email = sanitize_email(wp_unslash($_POST['manual_email'] ?? ($_POST['np_email'] ?? '')));
        $form_submitted = (isset($_POST['manual_add']) || isset($_POST['np_subscribe'])) && isset($_POST['np_members_nonce']) && wp_verify_nonce(wp_unslash($_POST['np_members_nonce']), 'np_manage_members');

        if ($form_submitted && $name && $email) {
            $existing = wp_cache_get("np_member_email_$email", 'np_members');
            if ($existing === false) {
                $query = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}np_members WHERE email = %s", $email);
                $existing = $wpdb->get_var($query);
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
        if (!empty($_POST['np_unsubscribe']) && isset($_POST['np_members_nonce']) && wp_verify_nonce(wp_unslash($_POST['np_members_nonce']), 'np_manage_members')) {
            $email = sanitize_email(wp_unslash($_POST['np_email'] ?? ''));
            $wpdb->update($table, ['status' => 'unsubscribed'], ['email' => $email]);
            wp_cache_delete("np_member_email_$email", 'np_members');
            echo '<div class="updated"><p>' . esc_html__('You have been unsubscribed.', 'nonprofit-manager') . '</p></div>';
        }

        $members = wp_cache_get('np_members_all', 'np_members');
        if ($members === false) {
            $query = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}np_members ORDER BY created_at DESC");
            $members = $wpdb->get_results($query);
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
?>
<script>
    document.getElementById("bulk-select-all").addEventListener("click", function(e) {
        const checkboxes = document.querySelectorAll("input[name='user_ids[]']");
        checkboxes.forEach(cb => cb.checked = e.target.checked);
    });
</script>
<?php
        echo '</div>';
    }
}