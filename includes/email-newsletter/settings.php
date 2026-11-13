<?php
defined('ABSPATH') || exit;

/**
 * File: includes/email-newsletter/settings.php
 * Description: Newsletter Settings page and settings registration for Nonprofit Manager plugin.
 */

/**
 * Render the Newsletter Settings Page
 */
function npmp_render_newsletter_settings() {
    npmp_verify_admin_access();

    npmp_admin_page_header(
        __('Newsletter Settings', 'nonprofit-manager'),
        __('Configure newsletter delivery and tracking settings.', 'nonprofit-manager')
    );
    ?>
    <form method="post" action="options.php">
        <?php
        settings_fields('npmp_newsletter_settings');
        do_settings_sections('npmp_newsletter_settings');
        submit_button();
        ?>
    </form>
    <?php
    npmp_admin_page_footer();
}

/**
 * Register Settings, Sections, and Fields
 */
add_action('admin_init', 'npmp_register_newsletter_settings');
function npmp_register_newsletter_settings() {
    register_setting(
        'npmp_newsletter_settings',
        'npmp_newsletter_rate_limit',
        [
            'type'              => 'integer',
            'default'           => 10,
            'sanitize_callback' => 'absint',
        ]
    );

    register_setting(
        'npmp_newsletter_settings',
        'npmp_newsletter_track_opens',
        [
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]
    );

    register_setting(
        'npmp_newsletter_settings',
        'npmp_newsletter_track_clicks',
        [
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]
    );

    register_setting(
        'npmp_newsletter_settings',
        'npmp_org_mailing_address',
        [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_textarea_field',
        ]
    );

    register_setting(
        'npmp_newsletter_settings',
        'npmp_newsletter_can_spam_footer',
        [
            'type'              => 'string',
            /* translators: Keep [organization], [address], and [unsubscribe_url] as they are. The plugin fills them in. */
            'default'           => __('You are receiving this email from [organization]. [address]. <a href="[unsubscribe_url]">Unsubscribe</a>', 'nonprofit-manager'),
            'sanitize_callback' => 'wp_kses_post',
        ]
    );

    add_settings_section(
        'npmp_newsletter_main',
        __('Delivery settings', 'nonprofit-manager'),
        null,
        'npmp_newsletter_settings'
    );

    add_settings_field(
        'npmp_newsletter_rate_limit',
        // The queue drains once a minute, so this is a per-minute figure. The
        // label said "per second", which sent sites a 60th of what they asked.
        __('Emails per minute', 'nonprofit-manager'),
        function () {
            $value = get_option('npmp_newsletter_rate_limit', 10);
            echo '<input type="number" id="npmp_newsletter_rate_limit" name="npmp_newsletter_rate_limit" value="' . esc_attr($value) . '" min="1" class="small-text" aria-describedby="npmp_newsletter_rate_limit_help" />';
            echo '<p class="description" id="npmp_newsletter_rate_limit_help">' . esc_html__('Nonprofit Manager sends this many newsletter emails each minute. The default is 10, or 600 an hour. Lower it if your email provider limits how fast you can send.', 'nonprofit-manager') . '</p>';
        },
        'npmp_newsletter_settings',
        'npmp_newsletter_main',
        array('label_for' => 'npmp_newsletter_rate_limit')
    );

    add_settings_field(
        'npmp_newsletter_track_opens',
        __('Open tracking', 'nonprofit-manager'),
        function () {
            $checked = checked(get_option('npmp_newsletter_track_opens', true), true, false);
            echo '<label for="npmp_newsletter_track_opens"><input type="checkbox" id="npmp_newsletter_track_opens" name="npmp_newsletter_track_opens" value="1" ' . esc_attr($checked) . ' /> ';
            echo esc_html__('Count who opens each newsletter', 'nonprofit-manager') . '</label>';
        },
        'npmp_newsletter_settings',
        'npmp_newsletter_main'
    );

    add_settings_field(
        'npmp_newsletter_track_clicks',
        __('Click tracking', 'nonprofit-manager'),
        function () {
            $checked = checked(get_option('npmp_newsletter_track_clicks', false), true, false);
            echo '<label for="npmp_newsletter_track_clicks"><input type="checkbox" id="npmp_newsletter_track_clicks" name="npmp_newsletter_track_clicks" value="1" ' . esc_attr($checked) . ' /> ';
            echo esc_html__('Count which links people click in your newsletters', 'nonprofit-manager') . '</label>';
            echo '<p class="description">' . esc_html__('You can see the counts on Newsletter Reports.', 'nonprofit-manager') . '</p>';
        },
        'npmp_newsletter_settings',
        'npmp_newsletter_main'
    );

    add_settings_field(
        'npmp_org_mailing_address',
        __('Mailing address', 'nonprofit-manager'),
        function () {
            $value       = get_option('npmp_org_mailing_address', '');
            $org_address = function_exists('npmp_format_postal_address') ? npmp_format_postal_address((array) get_option('npmp_org_settings', array())) : '';
            $placeholder = '' !== $org_address ? $org_address : __('123 Main St, Suite 100, Ventura, CA 93001', 'nonprofit-manager');
            echo '<textarea id="npmp_org_mailing_address" name="npmp_org_mailing_address" rows="3" cols="60" aria-describedby="npmp_org_mailing_address_help" placeholder="' . esc_attr($placeholder) . '">' . esc_textarea($value) . '</textarea>';
            echo '<p class="description" id="npmp_org_mailing_address_help">';
            if ('' !== $org_address) {
                echo esc_html__('CAN-SPAM requires a postal address in every newsletter footer. Leave this blank and the footer uses your organization address from General Settings. Type an address here only if newsletters need a different one.', 'nonprofit-manager');
            } else {
                echo esc_html__('CAN-SPAM requires a postal address in every newsletter footer. This fills the [address] placeholder. You can also add your organization address on General Settings, and the footer uses it when this box is blank. Until one of them has an address, the footer shows your admin email, which doesn\'t meet the rule.', 'nonprofit-manager');
                echo ' <a href="' . esc_url(admin_url('admin.php?page=npmp_general_settings')) . '">' . esc_html__('Open General Settings', 'nonprofit-manager') . '</a>';
            }
            echo '</p>';
        },
        'npmp_newsletter_settings',
        'npmp_newsletter_main',
        array('label_for' => 'npmp_org_mailing_address')
    );

    add_settings_field(
        'npmp_newsletter_can_spam_footer',
        __('Default CAN-SPAM footer', 'nonprofit-manager'),
        function () {
            $value = get_option('npmp_newsletter_can_spam_footer');
            echo '<textarea id="npmp_newsletter_can_spam_footer" name="npmp_newsletter_can_spam_footer" rows="4" cols="60" aria-describedby="npmp_newsletter_can_spam_footer_help">' . esc_textarea($value) . '</textarea>';
            echo '<p class="description" id="npmp_newsletter_can_spam_footer_help">' . esc_html__('You can use placeholders:', 'nonprofit-manager') . ' <code>[organization]</code>, <code>[address]</code>, <code>[unsubscribe_url]</code>. ' . esc_html__('[address] comes from the Mailing address field above, or your organization address when that field is blank. [unsubscribe_url] points to the unsubscribe page you picked in Membership Settings.', 'nonprofit-manager') . '</p>';
        },
        'npmp_newsletter_settings',
        'npmp_newsletter_main',
        array('label_for' => 'npmp_newsletter_can_spam_footer')
    );
}
