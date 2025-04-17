<?php
defined('ABSPATH') || exit;

/**
 * File: includes/email-newsletter/settings.php
 * Description: Newsletter Settings page and settings registration for Nonprofit Manager plugin.
 */

/**
 * Render the Newsletter Settings Page
 */
function np_render_newsletter_settings() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Newsletter Settings', 'nonprofit-manager'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('np_newsletter_settings');
            do_settings_sections('np_newsletter_settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

/**
 * Register Settings, Sections, and Fields
 */
add_action('admin_init', 'np_register_newsletter_settings');
function np_register_newsletter_settings() {
    register_setting(
        'np_newsletter_settings',
        'np_newsletter_rate_limit',
        [
            'type'              => 'integer',
            'default'           => 10,
            'sanitize_callback' => 'absint',
        ]
    );

    register_setting(
        'np_newsletter_settings',
        'np_newsletter_track_opens',
        [
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]
    );

    register_setting(
        'np_newsletter_settings',
        'np_newsletter_track_clicks',
        [
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]
    );

    register_setting(
        'np_newsletter_settings',
        'np_newsletter_can_spam_footer',
        [
            'type'              => 'string',
            'default'           => 'You are receiving this email from [organization]. [address]. <a href="[unsubscribe_url]">Unsubscribe</a>',
            'sanitize_callback' => 'wp_kses_post',
        ]
    );

    add_settings_section(
        'np_newsletter_main',
        __('Delivery Settings', 'nonprofit-manager'),
        null,
        'np_newsletter_settings'
    );

    add_settings_field(
        'np_newsletter_rate_limit',
        __('Max Emails Per Second', 'nonprofit-manager'),
        function () {
            $value = get_option('np_newsletter_rate_limit', 10);
            echo '<input type="number" name="np_newsletter_rate_limit" value="' . esc_attr($value) . '" min="1" class="small-text" />';
            echo '<p class="description">' . esc_html__('Default is 10. Reduce this if your email provider has strict rate limits.', 'nonprofit-manager') . '</p>';
        },
        'np_newsletter_settings',
        'np_newsletter_main'
    );

    add_settings_field(
        'np_newsletter_track_opens',
        __('Enable Open Tracking', 'nonprofit-manager'),
        function () {
            $checked = checked(get_option('np_newsletter_track_opens', true), true, false);
            echo '<input type="checkbox" name="np_newsletter_track_opens" value="1" ' . esc_attr($checked) . ' />';
        },
        'np_newsletter_settings',
        'np_newsletter_main'
    );

    add_settings_field(
        'np_newsletter_track_clicks',
        __('Enable Click Tracking', 'nonprofit-manager'),
        function () {
            $checked = checked(get_option('np_newsletter_track_clicks', false), true, false);
            echo '<input type="checkbox" name="np_newsletter_track_clicks" value="1" ' . esc_attr($checked) . ' />';
            echo '<p class="description">' . esc_html__('Link tracking support coming soon.', 'nonprofit-manager') . '</p>';
        },
        'np_newsletter_settings',
        'np_newsletter_main'
    );

    add_settings_field(
        'np_newsletter_can_spam_footer',
        __('Default CAN-SPAM Footer', 'nonprofit-manager'),
        function () {
            $value = get_option('np_newsletter_can_spam_footer');
            echo '<textarea name="np_newsletter_can_spam_footer" rows="4" cols="60">' . esc_textarea($value) . '</textarea>';
            echo '<p class="description">' . esc_html__('You can use placeholders:', 'nonprofit-manager') . ' <code>[organization]</code>, <code>[address]</code>, <code>[unsubscribe_url]</code>.</p>';
        },
        'np_newsletter_settings',
        'np_newsletter_main'
    );
}
