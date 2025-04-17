<?php
// includes/np-blocks.php

if (!defined('ABSPATH')) exit;

add_action('init', function () {
    // Register the minimal JS script for block picker support
    wp_register_script(
        'np-blocks-editor',
        plugins_url('../assets/js/np-blocks.js', __FILE__),
        ['wp-blocks', 'wp-element', 'wp-editor'],
        filemtime(plugin_dir_path(__FILE__) . '../assets/js/np-blocks.js'),
        true
    );

    // Define shortcode-based blocks
    $blocks = [
        'donation-form' => [
            'title'       => __('Donation Form', 'nonprofit-manager'),
            'description' => __('Displays a donation form with payment gateway options.', 'nonprofit-manager'),
            'shortcode'   => 'np_donation_form',
            'icon'        => 'money-alt',
            'keywords'    => ['donation', 'give', 'support'],
        ],
        'email-signup' => [
            'title'       => __('Email Signup Form', 'nonprofit-manager'),
            'description' => __('Displays a form for visitors to join your email list.', 'nonprofit-manager'),
            'shortcode'   => 'np_email_signup',
            'icon'        => 'email',
            'keywords'    => ['email', 'newsletter', 'subscribe'],
        ],
        'email-unsubscribe' => [
            'title'       => __('Email Unsubscribe Form', 'nonprofit-manager'),
            'description' => __('Displays a form for subscribers to remove themselves from the email list.', 'nonprofit-manager'),
            'shortcode'   => 'np_email_unsubscribe',
            'icon'        => 'email-alt',
            'keywords'    => ['unsubscribe', 'email', 'opt-out'],
        ],
    ];

    foreach ($blocks as $slug => $data) {
        register_block_type('nonprofit-manager/' . $slug, [
            'editor_script'   => 'np-blocks-editor',
            'render_callback' => function ($attrs = [], $content = '') use ($data) {
                return do_shortcode('[' . $data['shortcode'] . ']');
            },
            'attributes'  => [],
            'title'       => $data['title'],
            'description' => $data['description'],
            'category'    => 'widgets',
            'icon'        => $data['icon'],
            'keywords'    => $data['keywords'],
            'supports'    => ['html' => false],
        ]);
    }

    if (function_exists('wp_set_script_translations')) {
        wp_set_script_translations('np-blocks-editor', 'nonprofit-manager');
    }
});
