<?php
/**
 * File path: includes/onboarding/tour-data.php
 *
 * Free-tier tour step definitions. Pro appends additional steps via the
 * `npmp_tour_steps` filter.
 *
 * Each step is an associative array:
 *
 *   id          unique slug.
 *   page        WordPress admin screen ID this step renders on. Can be a
 *               regex-ish string with a wildcard '*' (matched via fnmatch).
 *               Use 'any' to mean "show on the next visible NPM page".
 *   target      CSS selector for the element to spotlight, or null for
 *               a centered full-page popover.
 *   placement   tooltip placement: 'top' | 'right' | 'bottom' | 'left' | 'center'.
 *   title       short title shown in the tooltip header.
 *   body        explanatory paragraph. Supports basic HTML.
 *   advance     how the user advances:
 *                 'next'    show a Next button (default).
 *                 'click'   wait for the spotlight target to be clicked.
 *                 'navigate' the Next button navigates to a different
 *                            admin page (URL in `next_url`).
 *   next_url    when advance='navigate': absolute or relative URL.
 *   skip_if     predicate that bypasses this step automatically (see
 *               NPMP_Tour::resolve_skip_predicate for forms).
 *   primary     short text for the primary button (overrides default 'Next').
 *   secondary   short text for the secondary button if a non-Next action.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Free-tier step list. The order is the tour order.
 *
 * @return array
 */
function npmp_tour_get_free_steps() {
	$features = (array) get_option( 'npmp_enabled_features', array() );
	$members  = ! array_key_exists( 'members', $features ) || ! empty( $features['members'] );
	$is_pro   = function_exists( 'npmp_is_pro' ) && npmp_is_pro();

	// The donations and events steps point at menu items, so they render
	// on whichever screen the tour reached last. With Member Tracking off
	// there is no Members or Email Settings screen, so that's General
	// Settings.
	$menu_page = $members ? 'membership_page_npmp_members' : 'nonprofit-manager_page_npmp_general_settings';

	$steps = array(
		array(
			'id'        => 'welcome',
			'page'      => 'toplevel_page_npmp_main',
			'target'    => null,
			'placement' => 'center',
			'title'     => __( 'Welcome to Nonprofit Manager', 'nonprofit-manager' ),
			'body'      => __( 'This short tour shows you the basics: your organization details, email delivery, bringing in your list, and where everything lives. It takes about three minutes, and you can end it at any step.', 'nonprofit-manager' ),
			'advance'   => 'next',
			'primary'   => __( 'Begin tour', 'nonprofit-manager' ),
		),
		array(
			'id'        => 'menu-general-settings',
			'page'      => 'toplevel_page_npmp_main',
			'target'    => '#toplevel_page_npmp_main a[href*="npmp_general_settings"], a[href*="page=npmp_general_settings"]',
			'placement' => 'right',
			'title'     => __( 'Tell the plugin who you are', 'nonprofit-manager' ),
			'body'      => __( 'Start with General Settings, where you enter your organization\'s name, its EIN if you have one, and your contact details.', 'nonprofit-manager' ),
			'advance'   => 'navigate',
			'next_url'  => 'admin.php?page=npmp_general_settings',
			'primary'   => __( 'Open General Settings', 'nonprofit-manager' ),
		),
		array(
			'id'        => 'org-identity-form',
			'page'      => 'nonprofit-manager_page_npmp_general_settings',
			'target'    => '#npmp_org_name, input[name="npmp_org_name"]',
			'placement' => 'right',
			'title'     => __( 'Your organization', 'nonprofit-manager' ),
			'body'      => __( 'Fill in your organization\'s name, type, EIN (optional), and mailing address, then click Save. The tour picks up again after the page reloads.', 'nonprofit-manager' ),
			'advance'   => 'next',
			'skip_if'   => 'option:npmp_org_settings.name',
		),
		array(
			'id'        => 'menu-email-settings',
			'page'      => 'nonprofit-manager_page_npmp_general_settings',
			'target'    => 'a[href*="page=npmp_email_settings"]',
			'placement' => 'right',
			'title'     => __( 'Make sure email arrives', 'nonprofit-manager' ),
			'body'      => __( 'Next, check that your email reaches people. Email Settings holds your From name and address and the service that sends your mail.', 'nonprofit-manager' ),
			'advance'   => 'navigate',
			'next_url'  => 'admin.php?page=npmp_email_settings',
			'primary'   => __( 'Open Email Settings', 'nonprofit-manager' ),
			'skip_if'   => 'npmp_tour_skip_without_members',
		),
		array(
			'id'        => 'email-provider',
			'page'      => 'nonprofit-manager_page_npmp_email_settings',
			'target'    => '#npmp_email_provider',
			'placement' => 'right',
			'title'     => __( 'How your email goes out', 'nonprofit-manager' ),
			'body'      => $is_pro
				? __( 'Pick Amazon SES, SendGrid, Mailgun, Postmark, Brevo, or Custom SMTP if you have an account with one. WordPress Default uses your server\'s built-in mail. You can change it any time.', 'nonprofit-manager' )
				: __( 'With the free plugin, you send through WordPress\'s built-in mail. On shared hosting that mail can land in spam, so send yourself a test first. Pro adds Amazon SES, your own SMTP server, and other services.', 'nonprofit-manager' ),
			'advance'   => 'next',
			'skip_if'   => 'npmp_tour_skip_without_members',
		),
		array(
			'id'        => 'email-test',
			'page'      => 'nonprofit-manager_page_npmp_email_settings',
			'target'    => '#npmp_test_email',
			'placement' => 'top',
			'title'     => __( 'Send a test email', 'nonprofit-manager' ),
			'body'      => __( 'Click Send Test Email and check your inbox before you move on. If it doesn\'t arrive, fix the provider settings first.', 'nonprofit-manager' ),
			'advance'   => 'next',
			'skip_if'   => 'npmp_tour_skip_without_members',
		),
		array(
			'id'        => 'menu-members',
			'page'      => 'nonprofit-manager_page_npmp_email_settings',
			'target'    => '#toplevel_page_npmp_membership, a[href*="page=npmp_members"]',
			'placement' => 'right',
			'title'     => __( 'Your contact list', 'nonprofit-manager' ),
			'body'      => __( 'Everyone you keep in touch with lives here. You can add people one at a time or import them from Mailchimp, Constant Contact, or a CSV file.', 'nonprofit-manager' ),
			'advance'   => 'navigate',
			'next_url'  => 'admin.php?page=npmp_members',
			'primary'   => __( 'Open the list', 'nonprofit-manager' ),
			'skip_if'   => 'npmp_tour_skip_without_members',
		),
		array(
			'id'        => 'menu-import',
			'page'      => 'membership_page_npmp_members',
			'target'    => 'a[href*="page=npmp_import"]',
			'placement' => 'right',
			'title'     => __( 'Bring your existing list', 'nonprofit-manager' ),
			'body'      => $is_pro
				? __( 'Have a list in Mailchimp or Constant Contact already? You can bring it in with the Import tool and match each field.', 'nonprofit-manager' )
				: __( 'Have a list in Mailchimp or Constant Contact already? You can bring it in with the Import tool and match each field. The free plugin imports up to 50 people at a time, and Pro removes the limit.', 'nonprofit-manager' ),
			'advance'   => 'next',
			'skip_if'   => 'npmp_tour_skip_without_members',
		),
		array(
			'id'        => 'menu-donations',
			'page'      => $menu_page,
			'target'    => '#toplevel_page_npmp_donations_group, a[href*="page=npmp_donation_settings"]',
			'placement' => 'right',
			'title'     => sprintf( /* translators: %s: lowercase plural word for donations, such as donations or contributions. */ __( 'Accept %s', 'nonprofit-manager' ), npmp_label( 'donations_lc' ) ),
			'body'      => $is_pro
				? __( 'Connect Stripe, PayPal, or Venmo here, then add the Donation Form block or the [npmp_donation_form] shortcode to any page.', 'nonprofit-manager' )
				: __( 'Connect Stripe, PayPal, or Venmo here, then add the Donation Form block or the [npmp_donation_form] shortcode to any page. Stripe gifts land in your records. PayPal and Venmo gifts show up in those accounts.', 'nonprofit-manager' ),
			'advance'   => 'next',
			'skip_if'   => 'npmp_tour_skip_without_donations',
		),
		array(
			'id'        => 'menu-events',
			'page'      => $menu_page,
			'target'    => '#toplevel_page_npmp-events, a[href*="page=npmp_event_settings"]',
			'placement' => 'right',
			'title'     => __( 'Events and the calendar', 'nonprofit-manager' ),
			'body'      => __( 'Add events here. Put the Events Calendar block or the [npmp_calendar] shortcode on any page, and visitors get a calendar with an iCal feed and a print button.', 'nonprofit-manager' ),
			'advance'   => 'next',
			// This step used to skip when the calendar was on, the reverse
			// of what its comment said.
			'skip_if'   => 'npmp_tour_skip_without_calendar',
		),
		array(
			'id'        => 'finish',
			'page'      => 'any',
			'target'    => null,
			'placement' => 'center',
			'title'     => __( "You're set up", 'nonprofit-manager' ),
			'body'      => __( 'You can rerun this tour any time from the Overview screen.', 'nonprofit-manager' ),
			'advance'   => 'finish',
			'primary'   => __( 'Finish', 'nonprofit-manager' ),
		),
	);

	return $steps;
}

/**
 * Whether one of the plugin's features is off. A feature missing from the
 * saved option counts with the plugin's default: members and donations on,
 * the calendar off.
 *
 * @param string $feature Feature key in npmp_enabled_features.
 * @return bool
 */
function npmp_tour_feature_off( $feature ) {
	$features = (array) get_option( 'npmp_enabled_features', array() );
	if ( ! array_key_exists( $feature, $features ) ) {
		return ! in_array( $feature, array( 'members', 'donations' ), true );
	}
	return empty( $features[ $feature ] );
}

/**
 * Tour skip predicate: the members, email, and import steps.
 *
 * @return bool
 */
function npmp_tour_skip_without_members() {
	return npmp_tour_feature_off( 'members' );
}

/**
 * Tour skip predicate: the donations step.
 *
 * @return bool
 */
function npmp_tour_skip_without_donations() {
	return npmp_tour_feature_off( 'donations' );
}

/**
 * Tour skip predicate: the events step.
 *
 * @return bool
 */
function npmp_tour_skip_without_calendar() {
	return npmp_tour_feature_off( 'calendar' );
}
