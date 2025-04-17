<?php
defined('ABSPATH') || exit;

add_shortcode('np_can_spam', function () {
    $footer = get_option('np_newsletter_can_spam_footer');
    
    $replacements = [
        '[organization]'    => get_bloginfo('name'),
        '[address]'         => get_option('admin_email'), // Replace with a real mailing address setting if desired
        '[unsubscribe_url]' => site_url('/unsubscribe') // Replace with your unsubscribe page
    ];

    return str_replace(array_keys($replacements), array_values($replacements), wp_kses_post($footer));
});
