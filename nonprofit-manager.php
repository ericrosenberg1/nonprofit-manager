<?php
/**
 * Plugin Name: Nonprofit Manager
 * Description: Comprehensive plugin to manage nonprofit activities including emails, memberships, donations, and events.
 * Version: 1.0.0
 * Author: Eric Rosenberg
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * File path: nonprofit-manager.php
 */

defined('ABSPATH') || exit;

// Load always-needed components
require_once plugin_dir_path(__FILE__) . 'includes/npmp-admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/npmp-blocks.php';
require_once plugin_dir_path(__FILE__) . 'includes/activation-hooks.php';
require_once plugin_dir_path(__FILE__) . 'includes/npmp-scripts.php';

// Load enabled feature config
$npmp_features = get_option('npmp_enabled_features', [
    'members'     => true,
    'newsletters' => false,
    'donations'   => true,
    'calendar'    => false,
]);

// Conditionally load feature modules
if (!empty($npmp_features['members'])) {
    require_once plugin_dir_path(__FILE__) . 'includes/npmp-members-settings.php';
    require_once plugin_dir_path(__FILE__) . 'includes/npmp-email-settings.php';
    require_once plugin_dir_path(__FILE__) . 'includes/npmp-email-delivery.php';
}

if (!empty($npmp_features['donations'])) {
    require_once plugin_dir_path(__FILE__) . 'includes/npmp-payments-settings.php';
}

if (!empty($npmp_features['newsletters'])) {
    require_once plugin_dir_path(__FILE__) . 'includes/npmp-email-newsletter.php';
}

// Register plugin admin menus
add_action('admin_menu', function () use ($npmp_features) {
    // Main plugin menu
    add_menu_page(
        'Nonprofit Manager',
        'Nonprofit Manager',
        'manage_options',
        'npmp_main',
        'npmp_render_main_plugin_page',
        'dashicons-groups',
        3.0
    );

    add_submenu_page('npmp_main', 'Overview', 'Overview', 'manage_options', 'npmp_main', 'npmp_render_main_plugin_page');

    if (!empty($npmp_features['members'])) {
        add_submenu_page('npmp_main', 'Members', 'Members', 'manage_options', 'npmp_members', 'npmp_render_members_page');
        add_submenu_page('npmp_main', 'Email Settings', 'Email Settings', 'manage_options', 'npmp_email_settings', 'npmp_render_email_settings_page');
        add_submenu_page('npmp_main', 'Email Delivery', 'Email Delivery', 'manage_options', 'npmp-email-delivery', 'npmp_render_email_delivery_page');
    }

    if (!empty($npmp_features['newsletters'])) {
        add_menu_page(
            'Email Newsletters',
            'Email Newsletters',
            'edit_posts',
            'npmp-newsletters',
            'npmp_render_newsletter_editor',
            'dashicons-email-alt',
            3.1
        );

        add_submenu_page('npmp-newsletters', 'New Newsletter', 'New Newsletter', 'edit_posts', 'npmp-newsletters', 'npmp_render_newsletter_editor');
        add_submenu_page('npmp-newsletters', 'Newsletter Templates', 'Newsletter Templates', 'edit_posts', 'npmp_newsletter_templates', 'npmp_render_newsletter_templates');
        add_submenu_page('npmp-newsletters', 'Newsletter Reports', 'Newsletter Reports', 'edit_posts', 'npmp_newsletter_reports', 'npmp_render_newsletter_reports');
        add_submenu_page('npmp-newsletters', 'Newsletter Settings', 'Newsletter Settings', 'manage_options', 'npmp_newsletter_settings', 'npmp_render_newsletter_settings');
    }

    if (!empty($npmp_features['donations'])) {
        add_menu_page(
            'Donations',
            'Donations',
            'manage_options',
            'npmp_donations_group',
            '__return_null',
            'dashicons-money-alt',
            3.2
        );
        add_submenu_page('npmp_donations_group', 'Payment Settings', 'Payment Settings', 'manage_options', 'npmp_payment_settings', 'npmp_render_payment_settings_page');
    }
});

// Load dynamic email delivery provider file
if (!empty($npmp_features['members'])) {
    $npmp_email_settings = get_option('npmp_email_delivery_settings');
    $method = $npmp_email_settings['method'] ?? 'wordpress';

    $delivery_files = [
        'smtp'     => 'includes/email/smtp.php',
        'ses'      => 'includes/email/ses.php',
        'sendgrid' => 'includes/email/sendgrid.php',
        'mailgun'  => 'includes/email/mailgun.php',
    ];

    if (isset($delivery_files[$method])) {
        $delivery_path = plugin_dir_path(__FILE__) . $delivery_files[$method];
        if (file_exists($delivery_path)) {
            require_once $delivery_path;
        }
    }
}
