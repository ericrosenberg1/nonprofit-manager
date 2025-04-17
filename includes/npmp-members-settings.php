<?php
// includes/npmp-members-settings.php

if (!defined('ABSPATH')) exit;

function npmp_render_members_page() {
    // Check if user has permission to access this page
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'nonprofit-manager'));
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

        echo '<div class="updated"><p>Member updated.</p></div>';
        return;
    }

    // Show edit form
    if (!empty($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id']) && current_user_can('manage_options')) {
        $id = intval($_GET['id']);
        $member = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        if (!$member) {
            echo '<div class="notice notice-error"><p>Member not found.</p></div>';
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>Edit Member</h1>';
        echo '<form method="post">';
        wp_nonce_field('np_manage_members', 'np_members_nonce');
        echo '<input type="hidden" name="member_id" value="' . esc_attr($member->id) . '">';
        echo '<table class="form-table">';
        echo '<tr><th>Name</th><td><input type="text" name="name" value="' . esc_attr($member->name) . '" class="regular-text" required></td></tr>';
        echo '<tr><th>Email</th><td><input type="email" name="email" value="' . esc_attr($member->email) . '" class="regular-text" required></td></tr>';
        echo '<tr><th>Membership Level</th><td><input type="text" name="membership_level" value="' . esc_attr($member->membership_level) . '" class="regular-text"></td></tr>';
        echo '<tr><th>Status</th><td><select name="status">';
        foreach (['subscribed', 'pending', 'unsubscribed'] as $status_option) {
            echo '<option value="' . esc_attr($status_option) . '"' . selected($member->status, $status_option, false) . '>' . esc_html(ucfirst($status_option)) . '</option>';
        }
        echo '</select></td></tr>';
        echo '</table>';
        submit_button('Save Changes', 'primary', 'np_save_member');
        echo '</form>';
        echo '</div>';
        return;
    }

    // Handle deletion
    if (!empty($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['id']) && 
        check_admin_referer('np_manage_members_delete_' . intval($_GET['id']))) {
        $wpdb->delete($table, ['id' => intval($_GET['id'])]);
        echo '<div class="updated"><p>Member deleted.</p></div>';
    }

    // Handle bulk deletion
    if (!empty($_POST['bulk_delete']) && isset($_POST['np_members_nonce']) && 
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['np_members_nonce'])), 'np_manage_members')) {
        $user_ids = array_map('intval', wp_unslash($_POST['user_ids'] ?? []));
        foreach ($user_ids as $id) {
            $wpdb->delete($table, ['id' => $id]);
        }
        echo '<div class="updated"><p>Selected members deleted.</p></div>';
    }

    // Handle manual addition and form-based signup
    $name = sanitize_text_field(wp_unslash($_POST['manual_name'] ?? $_POST['np_name'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['manual_email'] ?? $_POST['np_email'] ?? ''));
    $form_submitted = (!empty($_POST['manual_add']) || !empty($_POST['np_subscribe'])) && isset($_POST['np_members_nonce']) && 
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['np_members_nonce'])), 'np_manage_members');

    if ($form_submitted && $name && $email) {
        $existing = wp_cache_get("np_member_email_$email", 'np_members');
        if ($existing === false) {
            $existing = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE email = %s", $email));
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
            echo '<div class="updated"><p>Member added.</p></div>';
        } elseif (!empty($_POST['manual_add'])) {
            echo '<div class="notice notice-warning"><p>This email address is already subscribed.</p></div>';
        }
    }

    // Handle unsubscribe
    if (!empty($_POST['np_unsubscribe']) && isset($_POST['np_members_nonce']) && 
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['np_members_nonce'])), 'np_manage_members')) {
        $email = sanitize_email(wp_unslash($_POST['np_email'] ?? ''));
        $wpdb->update($table, ['status' => 'unsubscribed'], ['email' => $email]);
        wp_cache_delete("np_member_email_$email", 'np_members');
        echo '<div class="updated"><p>You have been unsubscribed.</p></div>';
    }

    $members = wp_cache_get('np_members_all', 'np_members');
    if ($members === false) {
        $members = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");
        wp_cache_set('np_members_all', $members, 'np_members', 300);
    }

    echo '<div class="wrap">';
    echo '<h1>Members & Subscribers</h1>';

    echo '<h2>Manually Add Member</h2>';
    echo '<form method="post" style="margin-bottom: 20px;">';
    wp_nonce_field('np_manage_members', 'np_members_nonce');
    echo '<input type="text" name="manual_name" placeholder="Name" required style="margin-right:10px;">';
    echo '<input type="email" name="manual_email" placeholder="Email" required style="margin-right:10px;">';
    echo '<input type="submit" name="manual_add" class="button button-primary" value="Add Member">';
    echo '</form>';

    echo '<form method="post">';
    wp_nonce_field('np_manage_members', 'np_members_nonce');
    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>';
    echo '<td class="check-column"><input type="checkbox" id="bulk-select-all"></td>';
    echo '<th>Name</th><th>Membership Level</th><th>Email Address</th><th>Status</th><th>Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($members as $member) {
        echo '<tr>';
        echo '<th class="check-column"><input type="checkbox" name="user_ids[]" value="' . esc_attr($member->id) . '"></th>';
        echo '<td>' . esc_html($member->name) . '</td>';
        echo '<td>' . esc_html($member->membership_level) . '</td>';
        echo '<td>' . esc_html($member->email) . '</td>';
        echo '<td>' . esc_html($member->status) . '</td>';
        echo '<td><a href="?page=np_members&action=edit&id=' . esc_attr($member->id) . '">Edit</a> | ';
        echo '<a href="' . esc_url(wp_nonce_url('?page=np_members&action=delete&id=' . $member->id, 'np_manage_members_delete_' . $member->id)) . '" onclick="return confirm(\'Are you sure?\')">Delete</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p><input type="submit" name="bulk_delete" class="button" value="Delete Selected"></p>';
    echo '</form>';
    echo '</div>';
}