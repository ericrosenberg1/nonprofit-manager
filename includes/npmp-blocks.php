<?php
// includes/npmp-blocks.php

if (!defined('ABSPATH')) exit;

add_action('init', function () {
    // Script registration is now handled by npmp-scripts.php
    
    // Define shortcode-based blocks
    $blocks = [
        'donation-form' => [
            'title'       => __('Donation Form', 'nonprofit-manager'),
            'description' => __('Displays a donation form with payment gateway options.', 'nonprofit-manager'),
            'shortcode'   => 'npmp_donation_form',
            'icon'        => 'money-alt',
            'keywords'    => ['donation', 'give', 'support'],
        ],
        'email-signup' => [
            'title'       => __('Email Signup Form', 'nonprofit-manager'),
            'description' => __('Displays a form for visitors to join your email list.', 'nonprofit-manager'),
            'shortcode'   => 'npmp_email_signup',
            'icon'        => 'email',
            'keywords'    => ['email', 'newsletter', 'subscribe'],
        ],
        'email-unsubscribe' => [
            'title'       => __('Email Unsubscribe Form', 'nonprofit-manager'),
            'description' => __('Displays a form for subscribers to remove themselves from the email list.', 'nonprofit-manager'),
            'shortcode'   => 'npmp_email_unsubscribe',
            'icon'        => 'email-alt',
            'keywords'    => ['unsubscribe', 'email', 'opt-out'],
        ],
    ];

    foreach ($blocks as $slug => $data) {
        register_block_type('nonprofit-manager/' . $slug, [
            'editor_script'   => 'npmp-blocks-editor',
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

    // Also register the email composer block, which is handled by its own JS file
    register_block_type('nonprofit/email-composer', [
        'editor_script' => 'npmp-email-composer-block',
        'render_callback' => function ($attrs = []) {
            return ''; // Server-rendered via PHP
        }
    ]);
    
    // Script translations are now handled by npmp-scripts.php
});
