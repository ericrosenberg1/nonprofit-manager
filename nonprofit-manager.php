<?php
/**
 * Plugin Name: Nonprofit Manager
 * Plugin URI: https://nonprofitmanager.app/
 * Description: Manage memberships, donations, newsletters and events from one plugin.
 * Version: 2026.11.10
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Rosenberg Digital LLC
 * Author URI: https://ericrosenberg.com
 * Text Domain: nonprofit-manager
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * File path: nonprofit-manager.php
 */
defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Core components (always loaded)
 * ---------------------------------------------------------------------- */
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-version.php';
// Kind of group (nonprofit, club, team...) and the words each one sees.
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-org-type.php';
// Site currency: formatting, gateway minor units, minimums. Pro calls these too.
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-currency.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-admin-helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-post-type-labels.php';
// Membership Manager role + npmp_staff_cap(). Loaded before any screen that
// registers a menu or handler against the staff capability.
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-staff-access.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-powered-by.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-marketing-optin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-review-nudge.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-credit-ask.php';
// Menu previews of the Pro screens (Automations, Custom Fields, Segments)
// for sites without Pro. Its hooks step aside when Pro is active.
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-pro-feature-pages.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/payments/npmp-gateway-rules.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-setup-wizard.php';
// Guided product tour + the org-identity data model it walks through. Loaded
// before the admin/general settings pages so they can render the org section.
require_once plugin_dir_path( __FILE__ ) . 'includes/onboarding/class-org-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/onboarding/class-tour.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-admin-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-rate-limit.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-general-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-scripts.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-forms-accent.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/activation-hooks.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-captcha.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-content-types.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/email/helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/email/smtp.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-dashboard-widgets.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-sentry.php';

/* -------------------------------------------------------------------------
 * Feature flags
 * ---------------------------------------------------------------------- */
$npmp_features = get_option(
	'npmp_enabled_features',
	array(
		'members'     => true,
		'newsletters' => false,
		'donations'   => true,
		'calendar'    => false,
		'social'      => false,
	)
);

/* -------------------------------------------------------------------------
 * Conditional modules
 * ---------------------------------------------------------------------- */
if ( ! empty( $npmp_features['members'] ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-members-settings.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-email-settings.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-membership-forms.php';

	// Member import wizard (CSV / XLSX / Google Sheets / Mailchimp / Constant
	// Contact). Row-capped in Free via npmp_import_max_rows(). Pro lifts the cap.
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/import-cap.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/mailchimp-api.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/constant-contact-api.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/cc-contact-map.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/date-added.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/class-import-manager.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/admin-import.php';

	// Importer for sites switching from MailPoet: contacts, lists, sender settings.
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/mailpoet-importer.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/mailpoet-hub-source.php';

	// Importer for sites switching from The Newsletter Plugin, on the MailPoet importer's writer.
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/newsletter-importer.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/pmpro-importer.php';
	// Ultimate Member, on the Paid Memberships Pro importer's writer.
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/ultimate-member-importer.php';

	// Past newsletters from MailPoet and The Newsletter Plugin, after their contacts.
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/past-newsletters.php';
}

if ( ! empty( $npmp_features['donations'] ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-payments-settings.php';
}

if ( ! empty( $npmp_features['newsletters'] ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-email-newsletter.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-subscription-preferences.php';
	// Scheduled emails: one email per event and "Send later" newsletters (Pro adds more).
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-scheduled-emails.php';
}

if ( ! empty( $npmp_features['calendar'] ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-calendar.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-events-loop.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-convert-to-event.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-calendar-blocks.php';
}

if ( ! empty( $npmp_features['social'] ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-social-sharing.php';
}

// Content blocks + shortcodes (email signup, unsubscribe, donation form, social
// share, contact form). Always loaded so the two new shortcodes are available.
// Each wrapper block registers only when its feature module was loaded above.
require_once plugin_dir_path( __FILE__ ) . 'includes/npmp-content-blocks.php';

// Importer for sites switching from The Events Calendar, plus 301s from its old event URLs.
require_once plugin_dir_path( __FILE__ ) . 'includes/import/tec-importer.php';

// Switch Plugins: one screen to import from, check, and retire every plugin we replace.
require_once plugin_dir_path( __FILE__ ) . 'includes/import/import-hub.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/import/tec-hub-source.php';

// Events from Events Manager, Sugar Calendar, Event Organiser, My Calendar, and WP Event Manager, on TEC's helpers.
require_once plugin_dir_path( __FILE__ ) . 'includes/import/event-importer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/import/events-manager-source.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/import/sugar-calendar-source.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/import/event-organiser-source.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/import/my-calendar-source.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/import/wp-event-manager-source.php';

// Donors and past gifts from GiveWP and Charitable. Needs contacts and donations.
if ( ! empty( $npmp_features['members'] ) && ! empty( $npmp_features['donations'] ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/past-gifts.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/gift-importer.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/givewp-source.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/charitable-source.php';
	// Past gifts from a CSV file (Zeffy, Givebutter, Donorbox, a spreadsheet), on the same engine.
	require_once plugin_dir_path( __FILE__ ) . 'includes/import/gift-csv-source.php';
}

/* -------------------------------------------------------------------------
 * Plugin action links
 * ---------------------------------------------------------------------- */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$custom_links = array();

		// Overview link
		$custom_links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=npmp_main' ) ) . '">' . esc_html__( 'Overview', 'nonprofit-manager' ) . '</a>';

		// Plugin website link
		$custom_links[] = '<a href="https://nonprofitmanager.app/" target="_blank">' . esc_html__( 'Website', 'nonprofit-manager' ) . '</a>';

		// Upgrade to Pro link
		if ( ! npmp_is_pro() ) {
			$custom_links[] = '<a href="' . esc_url( npmp_get_upgrade_url( 'plugins_list' ) ) . '" target="_blank">' . esc_html__( 'Upgrade to Pro', 'nonprofit-manager' ) . '</a>';
		}

		// Support link
		$custom_links[] = '<a href="https://wordpress.org/support/plugin/nonprofit-manager/" target="_blank">' . esc_html__( 'Support', 'nonprofit-manager' ) . '</a>';

		return array_merge( $custom_links, $links );
	}
);

/* -------------------------------------------------------------------------
 * Admin-menu scaffold (module menus register their own sub-items)
 * ---------------------------------------------------------------------- */
add_action(
	'admin_menu',
	static function () use ( $npmp_features ) {

		/* Main hub */
		add_menu_page(
			npmp_label( 'app_name' ),
			npmp_label( 'app_name' ),
			'manage_options',
			'npmp_main',
			'npmp_render_main_plugin_page',
			'dashicons-groups',
			3.0
		);
		npmp_pin_menu_hook( 'npmp_main', 'nonprofit-manager' );

		add_submenu_page( 'npmp_main', __( 'Overview', 'nonprofit-manager' ), __( 'Overview', 'nonprofit-manager' ), 'manage_options', 'npmp_main', 'npmp_render_main_plugin_page' );

		/* General Settings - Always available */
		add_submenu_page( 'npmp_main', __( 'General Settings', 'nonprofit-manager' ), __( 'General Settings', 'nonprofit-manager' ), 'manage_options', 'npmp_general_settings', 'npmp_render_general_settings_page' );

		/* Membership */
		if ( ! empty( $npmp_features['members'] ) ) {

			add_menu_page(
				__( 'Membership', 'nonprofit-manager' ),
				__( 'Membership', 'nonprofit-manager' ),
				npmp_staff_cap(),
				'npmp_membership',
				'npmp_render_membership_dashboard',
				'dashicons-admin-users',
				3.05
			);
			npmp_pin_menu_hook( 'npmp_membership', 'membership' );

			// One name per screen: the browser title and the h1 say
			// "Membership Dashboard", the menu says Membership > Dashboard.
			add_submenu_page( 'npmp_membership', __( 'Membership Dashboard', 'nonprofit-manager' ), __( 'Dashboard', 'nonprofit-manager' ), npmp_staff_cap(), 'npmp_membership', 'npmp_render_membership_dashboard' );
			add_submenu_page( 'npmp_membership', __( 'Contacts', 'nonprofit-manager' ), __( 'Contacts', 'nonprofit-manager' ), npmp_staff_cap(), 'npmp_members',            'npmp_render_members_page' );
			add_submenu_page( 'npmp_membership', __( 'Membership Settings', 'nonprofit-manager' ), __( 'Membership Settings', 'nonprofit-manager' ), 'manage_options', 'npmp_membership_forms',   'npmp_render_membership_forms_page' );
			add_submenu_page( 'npmp_main',       __( 'Email Settings', 'nonprofit-manager' ), __( 'Email Settings', 'nonprofit-manager' ), 'manage_options', 'npmp_email_settings',     'npmp_render_email_settings_page' );
		}

		/* Newsletters */
		if ( ! empty( $npmp_features['newsletters'] ) ) {
			// phpcs:disable WordPress.WP.Capabilities.Unknown -- edit_npmp_newsletters is the newsletter post types' own capability, resolved in npmp_filter_staff_caps().
			add_menu_page( __( 'Email Newsletters', 'nonprofit-manager' ), __( 'Email Newsletters', 'nonprofit-manager' ), 'edit_npmp_newsletters', 'npmp-newsletters', 'npmp_render_newsletter_editor', 'dashicons-email-alt', 3.1 );
			npmp_pin_menu_hook( 'npmp-newsletters', 'email-newsletters' );
			// The first item, and so the top-level link, opens the block
			// editor on a new newsletter. admin.php?page=npmp-newsletters
			// stays registered for old links, out of the menu.
			add_submenu_page( 'npmp-newsletters', __( 'Add Newsletter', 'nonprofit-manager' ), __( 'Add Newsletter', 'nonprofit-manager' ), 'edit_npmp_newsletters', 'post-new.php?post_type=npmp_newsletter' );
			remove_submenu_page( 'npmp-newsletters', 'npmp-newsletters' );
			add_submenu_page( 'npmp-newsletters', __( 'Newsletter Templates', 'nonprofit-manager' ), __( 'Newsletter Templates', 'nonprofit-manager' ), 'edit_npmp_newsletters', 'npmp_newsletter_templates', 'npmp_render_newsletter_templates' );
			add_submenu_page( 'npmp-newsletters', __( 'Newsletter Archive', 'nonprofit-manager' ), __( 'Newsletter Archive', 'nonprofit-manager' ),   'edit_npmp_newsletters', 'npmp_newsletter_archive',   'npmp_render_newsletter_archive' );
			add_submenu_page( 'npmp-newsletters', __( 'Newsletter Reports', 'nonprofit-manager' ), __( 'Newsletter Reports', 'nonprofit-manager' ),   'edit_npmp_newsletters', 'npmp_newsletter_reports',   'npmp_render_newsletter_reports' );
			add_submenu_page( 'npmp-newsletters', __( 'Newsletter Settings', 'nonprofit-manager' ), __( 'Newsletter Settings', 'nonprofit-manager' ),  'manage_options','npmp_newsletter_settings',  'npmp_render_newsletter_settings' );
			// phpcs:enable WordPress.WP.Capabilities.Unknown
		}

		/* Donations */
		if ( ! empty( $npmp_features['donations'] ) ) {
			add_menu_page( npmp_label( 'donations' ), npmp_label( 'donations' ), npmp_staff_cap(), 'npmp_donations_group', 'npmp_render_donations_dashboard', 'dashicons-money-alt', 3.2 );
			npmp_pin_menu_hook( 'npmp_donations_group', 'donations' );
			/* translators: %s: plural word for donations, such as Donations or Contributions. */
			$npmp_donations_dashboard = sprintf( __( '%s Dashboard', 'nonprofit-manager' ), npmp_label( 'donations' ) );
			add_submenu_page( 'npmp_donations_group', $npmp_donations_dashboard, __( 'Dashboard', 'nonprofit-manager' ), npmp_staff_cap(), 'npmp_donations_group', 'npmp_render_donations_dashboard' );
			/* translators: %s: singular word for a donation, such as Donation or Contribution. */
			$npmp_donation_settings = sprintf( __( '%s Settings', 'nonprofit-manager' ), npmp_label( 'donation' ) );
			add_submenu_page( 'npmp_donations_group', $npmp_donation_settings, $npmp_donation_settings, 'manage_options', 'npmp_donation_settings', 'npmp_render_donation_settings_page' );
			add_submenu_page( 'npmp_donations_group', __( 'Payment Settings', 'nonprofit-manager' ), __( 'Payment Settings', 'nonprofit-manager' ), 'manage_options', 'npmp_payment_settings', 'npmp_render_payment_settings_page' );
		}

		/* Calendar menus are fully registered inside npmp-calendar.php */
	}
);

/* -------------------------------------------------------------------------
 * Email delivery
 * ---------------------------------------------------------------------- */
// Transport selection is handled dynamically at send time by
// includes/email/smtp.php (loaded unconditionally above), which reads
// npmp_email_get_settings() and hooks phpmailer_init to configure SMTP for
// Custom SMTP or Amazon SES. Pro's API providers (SendGrid, Mailgun,
// Postmark, Brevo) send through pre_wp_mail instead. There used to be a second,
// provider-per-file loader here keyed on a `npmp_email_delivery_settings`
// option that nothing ever wrote, pointing at includes/email/ses.php,
// sendgrid.php, and mailgun.php, none of which exist. It was always a
// no-op (guarded by file_exists()) and has been removed.
