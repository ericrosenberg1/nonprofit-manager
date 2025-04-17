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
require_once plugin_dir_path(__FILE__) . 'includes/np-admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/np-blocks.php';
require_once plugin_dir_path(__FILE__) . 'includes/activation-hooks.php';

// Load enabled feature config
$np_features = get_option('np_enabled_features', [
    'members'     => true,
    'newsletters' => false,
    'donations'   => true,
    'calendar'    => false,
]);

// Conditionally load feature modules
if (!empty($np_features['members'])) {
    require_once plugin_dir_path(__FILE__) . 'includes/np-members-settings.php';
    require_once plugin_dir_path(__FILE__) . 'includes/np-email-settings.php';
    require_once plugin_dir_path(__FILE__) . 'includes/np-email-delivery.php';
}

if (!empty($np_features['donations'])) {
    require_once plugin_dir_path(__FILE__) . 'includes/np-payments-settings.php';
}

if (!empty($np_features['newsletters'])) {
    require_once plugin_dir_path(__FILE__) . 'includes/np-email-newsletter.php';
}

// Register plugin admin menus
add_action('admin_menu', function () use ($np_features) {
    // Main plugin menu
    add_menu_page(
        'Nonprofit Manager',
        'Nonprofit Manager',
        'manage_options',
        'np_main',
        'np_render_main_plugin_page',
        'dashicons-groups',
        3.0
    );

    add_submenu_page('np_main', 'Overview', 'Overview', 'manage_options', 'np_main', 'np_render_main_plugin_page');

    if (!empty($np_features['members'])) {
        add_submenu_page('np_main', 'Members', 'Members', 'manage_options', 'np_members', 'np_render_members_page');
        add_submenu_page('np_main', 'Email Settings', 'Email Settings', 'manage_options', 'np_email_settings', 'np_render_email_settings_page');
        add_submenu_page('np_main', 'Email Delivery', 'Email Delivery', 'manage_options', 'np-email-delivery', 'np_render_email_delivery_page');
    }

    if (!empty($np_features['newsletters'])) {
        add_menu_page(
            'Email Newsletters',
            'Email Newsletters',
            'edit_posts',
            'np-newsletters',
            'np_render_newsletter_editor',
            'dashicons-email-alt',
            3.1
        );

        add_submenu_page('np-newsletters', 'New Newsletter', 'New Newsletter', 'edit_posts', 'np-newsletters', 'np_render_newsletter_editor');
        add_submenu_page('np-newsletters', 'Newsletter Templates', 'Newsletter Templates', 'edit_posts', 'np_newsletter_templates', 'np_render_newsletter_templates');
        add_submenu_page('np-newsletters', 'Newsletter Reports', 'Newsletter Reports', 'edit_posts', 'np_newsletter_reports', 'np_render_newsletter_reports');
        add_submenu_page('np-newsletters', 'Newsletter Settings', 'Newsletter Settings', 'manage_options', 'np_newsletter_settings', 'np_render_newsletter_settings');
    }

    if (!empty($np_features['donations'])) {
        add_menu_page(
            'Donations',
            'Donations',
            'manage_options',
            'np_donations_group',
            '__return_null',
            'dashicons-money-alt',
            3.2
        );
        add_submenu_page('np_donations_group', 'Payment Settings', 'Payment Settings', 'manage_options', 'np_payment_settings', 'np_render_payment_settings_page');
    }
});

// Load dynamic email delivery provider file
if (!empty($np_features['members'])) {
    $np_email_settings = get_option('np_email_delivery_settings');
    $method = $np_email_settings['method'] ?? 'wordpress';

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
