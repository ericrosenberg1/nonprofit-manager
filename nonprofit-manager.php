<?php
/**
 * Plugin Name: Nonprofit Manager
 * Description: Manage memberships, donations, newsletters and events from one plugin.
 * Version: 1.0.0
 * Author: Eric Rosenberg
 * License: GPL‑2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * File path: nonprofit-manager.php
 */
defined( 'ABSPATH' ) || exit;

/* ─────────────────────────────────────
 * Core components (always loaded)
 * ──────────────────────────────────── */
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-admin-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-blocks.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/activation-hooks.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-scripts.php';

/* ─────────────────────────────────────
 * Feature flags
 * ──────────────────────────────────── */
$npmp_features = get_option(
	'npmp_enabled_features',
	array(
		'members'     => true,
		'newsletters' => false,
		'donations'   => true,
		'calendar'    => false,
	)
);

/* ─────────────────────────────────────
 * Conditional modules
 * ──────────────────────────────────── */
if ( ! empty( $npmp_features['members'] ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-members-settings.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-email-settings.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-membership-forms.php';
}

if ( ! empty( $npmp_features['donations'] ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-payments-settings.php';
}

if ( ! empty( $npmp_features['newsletters'] ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-email-newsletter.php';
}

if ( ! empty( $npmp_features['calendar'] ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-calendar.php';
}

/* ─────────────────────────────────────
 * Admin‑menu scaffold (module menus
 * register their own sub‑items)
 * ──────────────────────────────────── */
add_action(
	'admin_menu',
	static function () use ( $npmp_features ) {

		/* Main hub */
		add_menu_page(
			'Nonprofit Manager',
			'Nonprofit Manager',
			'manage_options',
			'npmp_main',
			'npmp_render_main_plugin_page',
			'dashicons-groups',
			3.0
		);

		add_submenu_page( 'npmp_main', 'Overview', 'Overview', 'manage_options', 'npmp_main', 'npmp_render_main_plugin_page' );

		/* Membership */
		if ( ! empty( $npmp_features['members'] ) ) {

			add_menu_page(
				'Membership',
				'Membership',
				'manage_options',
				'npmp_membership',
				'npmp_render_membership_dashboard',
				'dashicons-admin-users',
				3.05
			);

			add_submenu_page( 'npmp_membership', 'Member List',       'Member List',       'manage_options', 'npmp_members',            'npmp_render_members_page' );
			add_submenu_page( 'npmp_membership', 'Membership Forms',  'Membership Forms',  'manage_options', 'npmp_membership_forms',   'npmp_render_membership_forms_page' );
			add_submenu_page( 'npmp_main',       'Email Settings',    'Email Settings',    'manage_options', 'npmp_email_settings',     'npmp_render_email_settings_page' );
		}

		/* Newsletters */
		if ( ! empty( $npmp_features['newsletters'] ) ) {
			add_menu_page( 'Email Newsletters', 'Email Newsletters', 'edit_posts', 'npmp-newsletters', 'npmp_render_newsletter_editor', 'dashicons-email-alt', 3.1 );
			add_submenu_page( 'npmp-newsletters', 'New Newsletter',       'New Newsletter',       'edit_posts',    'npmp-newsletters',          'npmp_render_newsletter_editor' );
			add_submenu_page( 'npmp-newsletters', 'Newsletter Templates', 'Newsletter Templates', 'edit_posts',    'npmp_newsletter_templates', 'npmp_render_newsletter_templates' );
			add_submenu_page( 'npmp-newsletters', 'Newsletter Reports',   'Newsletter Reports',   'edit_posts',    'npmp_newsletter_reports',   'npmp_render_newsletter_reports' );
			add_submenu_page( 'npmp-newsletters', 'Newsletter Settings',  'Newsletter Settings',  'manage_options','npmp_newsletter_settings',  'npmp_render_newsletter_settings' );
		}

		/* Donations */
		if ( ! empty( $npmp_features['donations'] ) ) {
			add_menu_page( 'Donations', 'Donations', 'manage_options', 'npmp_donations_group', 'npmp_render_donations_dashboard', 'dashicons-money-alt', 3.2 );
			add_submenu_page( 'npmp_donations_group', 'Payment Settings', 'Payment Settings', 'manage_options', 'npmp_payment_settings', 'npmp_render_payment_settings_page' );
		}

		/* Calendar menus are fully registered inside npmp-calendar.php */
	}
);

/* ─────────────────────────────────────
 * Dynamic email‑delivery provider
 * ──────────────────────────────────── */
if ( ! empty( $npmp_features['members'] ) ) {

	$settings = get_option( 'npmp_email_delivery_settings', array() );
	$method   = $settings['method'] ?? 'wordpress';

	$drivers = array(
		'smtp'     => 'includes/email/smtp.php',
		'ses'      => 'includes/email/ses.php',
		'sendgrid' => 'includes/email/sendgrid.php',
		'mailgun'  => 'includes/email/mailgun.php',
	);

	if ( isset( $drivers[ $method ] ) ) {
		$path = plugin_dir_path( __FILE__ ) . $drivers[ $method ];
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
