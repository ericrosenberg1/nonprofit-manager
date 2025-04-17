<?php
defined('ABSPATH') || exit;

/**
 * Register Newsletter CPT
 */
add_action('init', 'np_register_newsletter_cpt');
function np_register_newsletter_cpt() {
    register_post_type('np_newsletter', [
        'labels' => [
            'name' => 'Newsletters',
            'singular_name' => 'Newsletter',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false, // We add it manually via our submenu
        'menu_position' => 56,
        'supports' => ['title', 'editor'],
        'show_in_rest' => true, // enables Gutenberg
        'capability_type' => 'post',
        'capabilities' => ['create_posts' => 'edit_posts'], // allow Editor+ roles
        'map_meta_cap' => true,
    ]);
}

/**
 * Render New Newsletter Page
 */
function np_render_newsletter_editor() {
    echo '<div class="wrap"><h1>' . esc_html__('New Newsletter', 'nonprofit-manager') . '</h1>';
    echo '<p>' . esc_html__('Use the editor below to create a newsletter. You can send a test email or queue it for delivery.', 'nonprofit-manager') . '</p>';
    echo '<a href="' . esc_url(admin_url('post-new.php?post_type=np_newsletter')) . '" class="button button-primary">' . esc_html__('Create New Newsletter', 'nonprofit-manager') . '</a>';
    echo '<hr>';

    // List past newsletters
    $newsletters = get_posts([
        'post_type' => 'np_newsletter',
        'posts_per_page' => 10,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    if ($newsletters) {
        echo '<h2>' . esc_html__('Recent Newsletters', 'nonprofit-manager') . '</h2><ul>';
        foreach ($newsletters as $post) {
            echo '<li><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html($post->post_title) . '</a></li>';
        }
        echo '</ul>';
    } else {
        echo '<p>' . esc_html__('No newsletters created yet.', 'nonprofit-manager') . '</p>';
    }

    echo '</div>';
}

/**
 * Add Sidebar Metabox to Editor
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'np_newsletter_send_controls',
        esc_html__('Send Newsletter', 'nonprofit-manager'),
        'np_newsletter_send_controls_html',
        'np_newsletter',
        'side',
        'high'
    );
});

function np_newsletter_send_controls_html($post) {
    $send_nonce = wp_create_nonce('np_send_newsletter_' . $post->ID);
    $test_nonce = wp_create_nonce('np_send_test_' . $post->ID);

    echo '<p><button type="button" class="button" id="np-send-test" data-postid="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($test_nonce) . '">' . esc_html__('Send Test to Me', 'nonprofit-manager') . '</button></p>';
    echo '<p><button type="button" class="button button-primary" id="np-send-newsletter" data-postid="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($send_nonce) . '">' . esc_html__('Send to All Members', 'nonprofit-manager') . '</button></p>';
}

/**
 * Enqueue Admin JS
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === 'post.php' || $hook === 'post-new.php') {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'np_newsletter') {
            wp_enqueue_script(
                'np-newsletter-editor',
                NP_NEWSLETTER_URL . 'assets/newsletter-editor.js',
                ['jquery'],
                '1.0.0', // Set version to ensure proper caching
                true
            );
        }
    }
});

/**
 * AJAX: Send Test Email
 */
add_action('wp_ajax_np_send_test_newsletter', function () {
    $post_id = isset($_POST['post_id']) ? (int) sanitize_text_field(wp_unslash($_POST['post_id'])) : 0;
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (
        empty($post_id) ||
        !current_user_can('edit_post', $post_id) ||
        !wp_verify_nonce($nonce, 'np_send_test_' . $post_id)
    ) {
        wp_send_json_error(esc_html__('Permission denied', 'nonprofit-manager'));
    }

    $user = wp_get_current_user();
    $sent = NP_Newsletter_Manager::send_test_email($post_id, $user->user_email);

    if ($sent) {
        wp_send_json_success(esc_html__('Test email sent to ', 'nonprofit-manager') . esc_html($user->user_email));
    } else {
        wp_send_json_error(esc_html__('Failed to send test email.', 'nonprofit-manager'));
    }
});

/**
 * AJAX: Queue Newsletter for Delivery
 */
add_action('wp_ajax_np_send_newsletter_now', function () {
    $post_id = isset($_POST['post_id']) ? (int) sanitize_text_field(wp_unslash($_POST['post_id'])) : 0;
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (
        empty($post_id) ||
        !current_user_can('edit_post', $post_id) ||
        !wp_verify_nonce($nonce, 'np_send_newsletter_' . $post_id)
    ) {
        wp_send_json_error(esc_html__('Permission denied', 'nonprofit-manager'));
    }

    NP_Newsletter_Manager::queue_newsletter($post_id);
    wp_send_json_success(esc_html__('Newsletter queued for delivery.', 'nonprofit-manager'));
});
