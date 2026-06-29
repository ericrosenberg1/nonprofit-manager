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
 *   body        explanatory paragraph; supports basic HTML.
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
	$steps = array(
		array(
			'id'        => 'welcome',
			'page'      => 'toplevel_page_npmp_main',
			'target'    => null,
			'placement' => 'center',
			'title'     => __( 'Welcome to Nonprofit Manager', 'nonprofit-manager' ),
			'body'      => __( 'This short tour walks you through the basics: org identity, email delivery, importing supporters, and where everything lives. About 3 minutes — you can end the tour at any step.', 'nonprofit-manager' ),
			'advance'   => 'next',
			'primary'   => __( 'Begin tour', 'nonprofit-manager' ),
		),
		array(
			'id'        => 'menu-general-settings',
			'page'      => 'toplevel_page_npmp_main',
			'target'    => '#toplevel_page_npmp_main a[href*="npmp_general_settings"], a[href*="page=npmp_general_settings"]',
			'placement' => 'right',
			'title'     => __( 'Tell the plugin who you are', 'nonprofit-manager' ),
			'body'      => __( 'First stop: General Settings. You\'ll enter your organization name, EIN if you have one, and contact details. This data auto-fills donation receipts and email signatures.', 'nonprofit-manager' ),
			'advance'   => 'navigate',
			'next_url'  => 'admin.php?page=npmp_general_settings',
			'primary'   => __( 'Open General Settings', 'nonprofit-manager' ),
		),
		array(
			'id'        => 'org-identity-form',
			'page'      => 'nonprofit-manager_page_npmp_general_settings',
			'target'    => '#npmp_org_name, input[name="npmp_org_name"]',
			'placement' => 'right',
			'title'     => __( 'Org identity', 'nonprofit-manager' ),
			'body'      => __( 'Fill in your organization name, type, EIN (optional), and mailing address. Click Save when done — the tour will continue automatically.', 'nonprofit-manager' ),
			'advance'   => 'next',
			'skip_if'   => 'option:npmp_org_settings.name',
		),
		array(
			'id'        => 'menu-email-settings',
			'page'      => 'nonprofit-manager_page_npmp_general_settings',
			'target'    => 'a[href*="page=npmp_email_settings"]',
			'placement' => 'right',
			'title'     => __( 'Make sure email delivers', 'nonprofit-manager' ),
			'body'      => __( "Now let's wire up email. We support five providers; AWS SES is free up to 62,000 messages/month for most nonprofits.", 'nonprofit-manager' ),
			'advance'   => 'navigate',
			'next_url'  => 'admin.php?page=npmp_email_settings',
			'primary'   => __( 'Open Email Settings', 'nonprofit-manager' ),
		),
		array(
			'id'        => 'email-provider',
			'page'      => 'nonprofit-manager_page_npmp_email_settings',
			'target'    => '#npmp_email_provider',
			'placement' => 'right',
			'title'     => __( 'Pick a provider', 'nonprofit-manager' ),
			'body'      => __( "Pick Amazon SES (free tier, best deliverability), Custom SMTP if your host gave you credentials, or WordPress Default to use the server's built-in mail. You can change this any time.", 'nonprofit-manager' ),
			'advance'   => 'next',
		),
		array(
			'id'        => 'email-test',
			'page'      => 'nonprofit-manager_page_npmp_email_settings',
			'target'    => 'button[name="npmp_send_test_email"], #npmp-send-test-email',
			'placement' => 'top',
			'title'     => __( 'Send a test email', 'nonprofit-manager' ),
			'body'      => __( "Hit Send Test Email. Check your inbox before moving on — if it doesn't arrive, fix the provider settings first.", 'nonprofit-manager' ),
			'advance'   => 'next',
		),
		array(
			'id'        => 'menu-members',
			'page'      => 'nonprofit-manager_page_npmp_email_settings',
			'target'    => '#toplevel_page_npmp_membership, a[href*="page=npmp_members"]',
			'placement' => 'right',
			'title'     => __( 'Your supporters live here', 'nonprofit-manager' ),
			'body'      => __( 'The Members menu is your central contact list. Add people one at a time, or bulk-import from Mailchimp / Constant Contact / a CSV.', 'nonprofit-manager' ),
			'advance'   => 'navigate',
			'next_url'  => 'admin.php?page=npmp_members',
			'primary'   => __( 'Open Members', 'nonprofit-manager' ),
		),
		array(
			'id'        => 'menu-import',
			'page'      => 'membership_page_npmp_members',
			'target'    => 'a[href*="page=npmp_import"]',
			'placement' => 'right',
			'title'     => __( 'Bring your existing list', 'nonprofit-manager' ),
			'body'      => __( "Got an audience in Mailchimp or Constant Contact already? The Import tool pulls them in with field mapping. Free plan imports up to 50 supporters per job; Pro removes the cap.", 'nonprofit-manager' ),
			'advance'   => 'next',
		),
		array(
			'id'        => 'menu-donations',
			'page'      => 'membership_page_npmp_members',
			'target'    => '#toplevel_page_npmp_donations_group, a[href*="page=npmp_donation_settings"]',
			'placement' => 'right',
			'title'     => __( 'Accept donations', 'nonprofit-manager' ),
			'body'      => __( 'Donations menu — connect Stripe and/or PayPal, drop the [npmp_donation] shortcode on any page, watch contributions roll in. Donor records auto-link to your member list.', 'nonprofit-manager' ),
			'advance'   => 'next',
		),
		array(
			'id'        => 'menu-events',
			'page'      => 'membership_page_npmp_members',
			'target'    => '#toplevel_page_npmp-events, a[href*="page=npmp_event_settings"]',
			'placement' => 'right',
			'title'     => __( 'Events + calendar', 'nonprofit-manager' ),
			'body'      => __( 'Public events calendar with iCal feed. Drop [npmp_calendar] on a page or use the auto-injected Calendar page we create for you.', 'nonprofit-manager' ),
			'advance'   => 'next',
			'skip_if'   => 'option:npmp_enabled_features.calendar', // skip if calendar disabled
		),
		array(
			'id'        => 'finish',
			'page'      => 'any',
			'target'    => null,
			'placement' => 'center',
			'title'     => __( "You're set up", 'nonprofit-manager' ),
			'body'      => __( "Everything's configured. You can rerun this tour any time from the NPM overview page. Welcome — happy organizing.", 'nonprofit-manager' ),
			'advance'   => 'finish',
			'primary'   => __( 'Finish', 'nonprofit-manager' ),
		),
	);

	return $steps;
}
