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

// ─────────────────────────────────────
// Core / Always‑needed components
// ─────────────────────────────────────
require_once plugin_dir_path(__FILE__) . 'includes/npmp-admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/npmp-blocks.php';
require_once plugin_dir_path(__FILE__) . 'includes/activation-hooks.php';
require_once plugin_dir_path(__FILE__) . 'includes/npmp-scripts.php';

// ─────────────────────────────────────
// Feature flags (saved in wp_options)
// ─────────────────────────────────────
$npmp_features = get_option('npmp_enabled_features', [
    'members'     => true,
    'newsletters' => false,
    'donations'   => true,
    'calendar'    => false,
]);

// ─────────────────────────────────────
// Conditional module loading
// ─────────────────────────────────────
if (!empty($npmp_features['members'])) {
    require_once plugin_dir_path(__FILE__) . 'includes/npmp-members-settings.php';
    require_once plugin_dir_path(__FILE__) . 'includes/npmp-email-settings.php';
    // ▶ Email‑Delivery page removed, so this file is no longer required
    // require_once plugin_dir_path(__FILE__) . 'includes/npmp-email-delivery.php';

    // NEW: membership‑forms page callback
    require_once plugin_dir_path(__FILE__) . 'includes/npmp-membership-forms.php';
}

if (!empty($npmp_features['donations'])) {
    require_once plugin_dir_path(__FILE__) . 'includes/npmp-payments-settings.php';
}

if (!empty($npmp_features['newsletters'])) {
    require_once plugin_dir_path(__FILE__) . 'includes/npmp-email-newsletter.php';
}

// ─────────────────────────────────────
// Admin‑menu registration
// ─────────────────────────────────────
add_action('admin_menu', function () use ($npmp_features) {

    /* ──────────────
     * Main plugin hub
     * ────────────── */
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

    /* ─────────────────────────────────────
     * Membership section (NEW, conditional)
     * Appears BETWEEN Nonprofit Manager (3.0)
     * and Email Newsletters (3.1)
     * ───────────────────────────────────── */
    if (!empty($npmp_features['members'])) {

        add_menu_page(
            'Membership',
            'Membership',
            'manage_options',
            'npmp_membership',
            'npmp_render_membership_dashboard',
            'dashicons-admin-users',
            3.05   // floats fit between 3.0 and 3.1
        );

        // Members (moved here)
        add_submenu_page(
            'npmp_membership',
            'Member List',
            'Member List',
            'manage_options',
            'npmp_members',
            'npmp_render_members_page'
        );

        // Membership Forms (NEW)
        add_submenu_page(
            'npmp_membership',
            'Membership Forms',
            'Membership Forms',
            'manage_options',
            'npmp_membership_forms',
            'npmp_render_membership_forms_page'
        );

        // Email‑related settings stay in the main hub
        add_submenu_page('npmp_main', 'Email Settings', 'Email Settings', 'manage_options', 'npmp_email_settings', 'npmp_render_email_settings_page');

        /* Email Delivery submenu removed
           (page was empty). */
        // add_submenu_page(..., 'Email Delivery', ...);
    }

    /* ────────────────
     * Email Newsletters
     * ──────────────── */
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

        add_submenu_page('npmp-newsletters', 'New Newsletter',        'New Newsletter',        'edit_posts',    'npmp-newsletters',          'npmp_render_newsletter_editor');
        add_submenu_page('npmp-newsletters', 'Newsletter Templates',  'Newsletter Templates',  'edit_posts',    'npmp_newsletter_templates', 'npmp_render_newsletter_templates');
        add_submenu_page('npmp-newsletters', 'Newsletter Reports',    'Newsletter Reports',    'edit_posts',    'npmp_newsletter_reports',   'npmp_render_newsletter_reports');
        add_submenu_page('npmp-newsletters', 'Newsletter Settings',   'Newsletter Settings',   'manage_options','npmp_newsletter_settings',  'npmp_render_newsletter_settings');
    }

    /* ─────────────
     * Donations menu
     * ───────────── */
    if (!empty($npmp_features['donations'])) {

        add_menu_page(
            'Donations',
            'Donations',
            'manage_options',
            'npmp_donations_group',
            'npmp_render_donations_dashboard',
            'dashicons-money-alt',
            3.2
        );
        add_submenu_page('npmp_donations_group', 'Payment Settings', 'Payment Settings', 'manage_options', 'npmp_payment_settings', 'npmp_render_payment_settings_page');
    }
});

/* ─────────────────────────────────────────
 * Dynamic email‑delivery provider loading
 * (sending logic still required, even though
 * admin “Email Delivery” page was removed)
 * ───────────────────────────────────────── */
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
