=== Nonprofit Manager – Donations, Membership, Newsletters & Events ===
Contributors: eric1985
Tags: nonprofit, donations, membership, fundraising, newsletter
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 2026.09.21
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The all-in-one WordPress plugin for small nonprofits and clubs. Manage members, donations, newsletters, and events in one place. Free.

== Description ==

Nonprofit Manager is the free, all-in-one WordPress plugin for small nonprofits, clubs, and community groups. It brings membership management, online donations, email newsletters, and an event calendar together in one place, so you don't have to bolt four separate plugins together to run your organization.

Most tools do one job. Donation plugins only take donations. Membership plugins only track members. Nonprofit Manager keeps your members and donors in one system, right next to the newsletters you send them and the events you invite them to. You spend less time wrangling software and more time on your mission. Learn more at [nonprofitmanager.app](https://nonprofitmanager.app/).

= Features =

Everything without an asterisk is free. Features marked with an asterisk (*) come with [Nonprofit Manager Pro](https://nonprofitmanager.app/pricing) when you're ready for them.

**Members and donors**

* Track members with membership levels, filtering, and bulk actions
* Members and donors share one contact list, so you can see anyone's lifetime giving at a glance
* Add signup and unsubscribe forms to any page as blocks or shortcodes, protected by Cloudflare Turnstile or Google reCAPTCHA
* Import your existing list from CSV, XLSX, Google Sheets, Mailchimp, or Constant Contact
* Custom member fields with 8 field types and drag-and-drop ordering*
* Segment members with an AND/OR condition builder*

**Donations**

* Accept one-time donations through PayPal, Venmo, and Stripe
* Drop a donation form on any page as a block or shortcode, with a thank-you message on the page
* Recurring donations*
* Membership dues auto-billing*
* Donor thank-you emails with the gift amount and date*
* PayPal Smart Buttons, with a server-side verification record for every capture*

**Newsletters and email**

* Write newsletters in the editor you already know (Gutenberg), with reusable templates, headers, and footers
* See who opens and clicks, tracked in dedicated tables that won't bloat your database
* Send yourself a test first, let rate limiting pace large sends, and stay CAN-SPAM compliant
* One-click unsubscribe with RFC 8058 List-Unsubscribe headers, which Gmail and Yahoo now expect
* Tell subscribers about new posts and events instantly or in an automatic weekly digest, and let them pick their own preferences
* Email automation workflows: welcome emails, donation receipts, and expiry reminders*
* Send through AWS SES, Brevo, SendGrid, Mailgun, or Postmark*

**Events**

* Event calendar with Month, Week, and List views, plus Calendar and Upcoming Events blocks for any page
* iCal feed, quick-add from the dashboard, and one-click convert of any post or page into an event

**Sharing and outreach**

* Auto-share new posts and events to Facebook and X, with {title}, {url}, {excerpt} placeholders
* Auto-share to Reddit, Bluesky, Mastodon, Threads, and Nextdoor*
* Give visitors share buttons and a contact form, each as a block or shortcode

== External Services ==

Nonprofit Manager talks to outside services only where a feature needs it. Nothing below runs unless you turn that feature on or enter your own credentials.

**Product update emails (optional, off by default)**

If you tick the product update box in the setup wizard, your email address and display name are sent once to our newsletter system at listmonk.ericrosenberg.com to start a double opt-in signup. You then get one confirmation email, and nothing else is sent unless you click it. Leave the box unticked and no request is made. Data sent: your email address and display name, at that one moment. Service: Listmonk, self-hosted by Rosenberg Digital LLC. Privacy policy: https://nonprofitmanager.app/privacy-policy/

**Nonprofit Manager Pro licensing (only with Pro installed)**

The free plugin on its own does not contact our servers. If you also install Nonprofit Manager Pro, Pro checks your license and looks for updates at nonprofitmanager.app, sending your license key, your site URL, and the installed version. Terms: https://nonprofitmanager.app/terms-of-service/ Privacy policy: https://nonprofitmanager.app/privacy-policy/

**Payment processors (only when you enable one)**

Donations are sent to the processor you configure with your own account credentials. Stripe (api.stripe.com), PayPal (paypal.com), and Venmo (venmo.com) receive donation amounts and the donor details you collect. Stripe: https://stripe.com/legal and https://stripe.com/privacy PayPal and Venmo: https://www.paypal.com/legalhub/useragreement-full and https://www.paypal.com/privacy

**Spam protection (only when you enable one)**

If you turn on a captcha, form submissions are verified with Cloudflare Turnstile (challenges.cloudflare.com) or Google reCAPTCHA (google.com/recaptcha), using your own site keys. Cloudflare: https://www.cloudflare.com/website-terms/ and https://www.cloudflare.com/privacypolicy/ Google: https://policies.google.com/terms and https://policies.google.com/privacy

**List imports (only when you run one)**

Importing from Mailchimp or Constant Contact reads your list using the API key you supply. Mailchimp: https://mailchimp.com/legal/terms/ and https://www.intuit.com/privacy/statement/ Constant Contact: https://www.constantcontact.com/legal/terms-of-service and https://www.constantcontact.com/legal/privacy-statement

**Social sharing (only when you connect an account)**

Auto-sharing posts sends the title, link, and excerpt you configure to the networks you connect, using your own account credentials.

== Installation ==

1. Install from the WordPress Plugin Directory (search for "Nonprofit Manager"), or upload the `nonprofit-manager` folder to `/wp-content/plugins/`.
2. Activate the plugin from the Plugins menu.
3. Walk through the setup wizard and turn on only the features you need.
4. Add your payment details, email settings, and membership levels, and you're ready to welcome supporters.

== Frequently Asked Questions ==

= Is Nonprofit Manager free? =

Yes. Membership management, donations, newsletters, and events are all included in the free plugin, with no trial period or locked screens. [Nonprofit Manager Pro](https://nonprofitmanager.app/pricing) adds features like recurring donations, email automation, and unlimited imports when you need them.

= What payment gateways are supported? =

PayPal, Venmo, and Stripe for one-time donations, all in the free plugin. Recurring donations and membership dues auto-billing come with Nonprofit Manager Pro.

= Can I send email newsletters? =

Yes. You write newsletters in the Gutenberg editor, reuse templates, headers, and footers, and see who opened and clicked. One-click unsubscribe and a CAN-SPAM footer are built in.

= Does it work with my theme? =

Yes. Nonprofit Manager works with any properly coded WordPress theme, and its forms and shortcodes pick up your theme's styling automatically.

= Can I convert existing posts into events? =

Yes. Use the "Convert to Event" action on any post or page. It carries your content over and lets you set the date, time, and location.

= How do I import my existing email list? =

Go to Membership > Import and choose CSV, XLSX, Google Sheets, Mailchimp, or Constant Contact. The importer detects your columns for you. The free plugin imports up to 50 supporters per job, and Pro removes the cap.

= Where can I get support? =

Ask in the WordPress.org support forums and we'll help. Pro includes email support at support@nonprofitmanager.app, with priority replies on the Multi-Site and Developer plans.

== Screenshots ==

1. Dashboard overview showing membership and donation statistics
2. Member management interface with filtering and bulk actions
3. Email newsletter editor with Gutenberg blocks
4. Newsletter template builder with header/footer support
5. Donation form with PayPal, Venmo, and Stripe options
6. Event calendar management interface
7. Payment gateway settings for accepting donations
8. Subscriber notification preference management

== Changelog ==

= 2026.09.21 =
* Only editors and admins can send newsletters.
* Contributors' converted events wait for review.
* Share Now only posts published content.
* Signup no longer re-subscribes people who opted out.
* Unsubscribe links ask for one confirming click.
* Import files are stored privately.
* Fixed Stripe gifts lost on pages with anchors.
* Fixed PayPal gifts missing from combined donation forms.
* Fixed newsletter links with encoded characters.
* Fixed duplicate events in calendar feeds.
* Pro newsletter segments now apply.

= 2026.09.20 =
* Fixed Constant Contact imports.
* Removed SparkPost as an email service.
* Imports no longer trigger Pro's automations.

= 2026.09.19 =
* Added Stripe card payments for one-time gifts.
* Fixed the shortcode named in the guided tour.
* Fixed the note under the EIN field.

= 2026.09.18 =
* Version lockstep with Nonprofit Manager Pro.

= 2026.09.17 =
* The "Powered by" link is now offered after your first donation.
* The review request waits three days after that.

= 2026.09.16 =
* Automatic updates now cover both plugins together.

= 2026.09.15 =
* Added a regression test for the Members screen totals.

= 2026.09.14 =
* Faster Donations screen, donor totals and subscriber counts.

= 2026.09.13 =
* Version lockstep with Nonprofit Manager Pro.

= 2026.09.12 =
* Fixed subscriber notifications on large lists.
* Faster Dashboard and Overview counts.

= 2026.09.11 =
* Listed every outside service the plugin can contact.

= 2026.09.10 =
* Fixed the Pro price in an upgrade notice.
* Added two optional opt-ins to the setup wizard.

= 2026.09.9 =
* Added a warning when free and Pro versions differ.

= 2026.09.8 =
* Removed two development files from the plugin zip.

= 2026.09.7 =
* Version lockstep with Nonprofit Manager Pro.

= 2026.09.6 =
* Version lockstep with Nonprofit Manager Pro.

= 2026.09.5 =
* Version lockstep with Nonprofit Manager Pro.

= 2026.09.4 =
* Fixed missing form styles on the membership join form.
* Added the npmp_form_style_shortcodes filter.

= 2026.09.3 =
* Version lockstep with Nonprofit Manager Pro.

= 2026.09.2 =
* Fixed "One_time" in donation history.
* Version lockstep with Nonprofit Manager Pro.

= 2026.08.4 =
* Added a warning when PayPal donations can't be verified.

= 2026.08.3 =
* Fixed sharing to X.
* Fixed shifted calendar event times.
* Fixed the default level for new email signups.
* Fixed unreadable dates saving as 1970.
* Fixed newsletter links that point off-site.
* Fixed the "All Members" checkbox losing its state.
* Fixed donation amounts undercharged by a cent.
* Fixed the Annual Recurring Donations total.
* Closed a gap that allowed unpaid donation records.
* Added missing permission checks on three settings screens.
* Faster newsletter sending and member counts.
* Raised the minimum PHP version to 8.1.

= 2026.08.2 =
* Version lockstep with Nonprofit Manager Pro.

= 2026.08.1 =
* Moved to a new home at nonprofitmanager.app.
* Refreshed the plugin listing.

= 2026.07.5 =
* Fixed a permissions error on the Social Sharing page.
* Large member imports now run in the background.
* Newsletter sending no longer bloats your database.

= 2026.07.4 =
* Fixed "Force From Address" changing other plugins' mail.
* PayPal donations now keep a verification record.
* The weekly digest sends in batches.
* Large CSV and XLSX imports use less memory.
* Open and click tracking no longer bloats your database.

= 2026.07.3 =
* Fixed recurring Stripe donations charging only once.
* Fixed the thank-you page and email for Stripe donors.
* Donations are recorded only after Stripe confirms payment.
* Fixed the PayPal Smart Buttons form.
* The unsubscribe form now sends a confirmation link.
* Stored API keys are no longer shown back in settings.
* Fixed newsletter click-tracking links being altered.
* Fixed Pro custom fields, segments and notification preferences.
* Subscriber notifications no longer run while you publish.
* Faster admin dashboard, member list and calendar feed.
* Removed an upgrade notice for a feature that doesn't exist.

= 2026.07.2 =
* Added five editor blocks for signup, unsubscribe, donations, sharing and contact.
* Added visitor social sharing buttons.
* Added a general contact form.

= 2026.07.1 =
* Added an optional "Powered by Nonprofit Manager" link.
* Added a dismissible review reminder.
* Fixed a PHP warning on the Setup screen.

= 2026.06.4 =
* Version lockstep with Nonprofit Manager Pro.

= 2026.06.3 =
* Added a redesigned events calendar with Month, Week and List views.
* Added calendar display options and two calendar blocks.
* Added an "Edit Event" button in the admin toolbar.
* Fixed the calendar rendering twice.
* Fixed quick-added events and members.
* PayPal's script loads only on donation pages.

= 2026.06.2 =
* Added the member import wizard and a guided tour.
* Added one-click unsubscribe headers.
* Added default styles for the plugin's forms.
* Added an organization mailing address setting.
* The unsubscribe page is created on activation.
* Fixed [unsubscribe_url] and the CAN-SPAM footer address.
* Free and Pro now ship on the same version number.

= 2.0.1 =
* Pro features work with Pro installed. A license is needed for updates.
* Fixed a conflict when upgrading Pro.

= 2.0.0 =
* Added the Stripe gateway for one-time donations.
* Added auto-sharing of posts and events to Facebook and X.
* Added subscriber notification preferences.
* Added one-click conversion of a post or page to an event.
* Added click tracking for newsletter links.
* Added a manage preferences page for subscribers.
* Added a weekly digest email.
* Newsletter tracking links no longer expire.
* Added a security check to the multi-gateway Stripe form.
* Updated the upgrade link.
* Security fix: added a missing check to the Stripe form.
* Pro: License key system with activation, deactivation, and auto-updates
* Pro: Recurring donations with Stripe subscription management
* Pro: Custom member fields (8 field types, drag-and-drop ordering)
* Pro: Email automation engine with 5 trigger types
* Pro: Advanced member segmentation with AND/OR condition builder
* Pro: Import from Mailchimp, Constant Contact, CSV, XLSX, Google Sheets
* Pro: 5 additional social networks (Reddit, Bluesky, Mastodon, Threads, Nextdoor)
* Pro: Guided email provider setup wizard with connection testing
* Pro: Email validation before sending to external provider APIs

= 1.1.3 =
* Added plugin action links.
* Added membership and donation summaries to the overview.
* Improved the main page layout.
* Membership levels moved to Membership Settings.
* Renamed "Membership Forms" to "Membership Settings".
* Tested up to WordPress 6.8.3.

= 1.1.2 =
* Fixed the dashboard widget's member count.

= 1.1.1 =
* Fixed the Venmo payment button.

= 1.1 =
* Added newsletter templates.
* Added a "Send to All Members" option.
* Added a version mismatch warning for Pro.
* Added admin helper functions.
* Security fix: verified nonce checks and sanitization.
* Faster member counting.
* More consistent admin screens.
* Fixed newsletter audience selection and tracking.
* Fixed PayPal button rendering.

= 1.0.2 =
* Fixed Amazon SES validation.
* Fixed PayPal and Venmo button display.
* Fixed dashboard widget member counts.
* Better email delivery error handling.

= 1.0.1 =
* Fixed activation hooks.
* Improved the setup wizard.
* Clearer payment error messages.

= 1.0.0 =
* Initial release
* Membership management system
* Donation processing (PayPal, Venmo)
* Basic email newsletter functionality
* Event calendar
* Setup wizard

== Upgrade Notice ==

= 2026.09.21 =
Security and reliability fixes. Only editors and administrators can send newsletters, the signup form no longer re-subscribes people who opted out, and Stripe and PayPal gifts that were missed before are now recorded.

= 2026.09.20 =
Constant Contact imports now bring in your contacts, with opt-outs carried over. SparkPost is no longer offered as an email service.

= 2026.09.19 =
Stripe card payments are now part of the free version for one-time gifts. Monthly giving stays in Pro.

= 2026.09.18 =
No change to the free plugin. Lockstep with Nonprofit Manager Pro 2026.09.18.

= 2026.09.17 =
The optional "Powered by" link is now offered once after your first donation instead of in the setup wizard. Nothing changes unless you tick the box. Lockstep with Nonprofit Manager Pro 2026.09.17.

= 2026.09.16 =
No change to the free plugin. If you also run Nonprofit Manager Pro, updating Pro to 2026.09.16 turns on automatic updates for both plugins, once, so each release installs on both together. Switch them off in Plugins any time and they stay off.

= 2026.09.2 =
Small display fix in a member's donation history. Worth taking if you also run Nonprofit Manager Pro, which fixes a Monthly Recurring Revenue total that read too high for annual subscriptions.

= 2026.08.4 =
Adds an admin warning when PayPal donations are being accepted without a PayPal API secret saved, since donations cannot be verified against PayPal in that state.

= 2026.08.3 =
Recommended update. Fixes a broken X/Twitter share, shifted calendar event times, an ignored default-membership-level setting, a one-cent undercharge on some donation amounts, and an annual recurring total that read low. Also closes a gap that let a logged-out visitor log a fake donation. Raises the minimum PHP version to 8.1, so sites on PHP 8.0 or older will not be offered this update until they upgrade.

= 2026.08.2 =
Maintenance release keeping the free plugin in lockstep with Pro 2026.08.2. No functional changes to the free plugin.

= 2026.08.1 =
Maintenance update. The plugin's home moved to nonprofitmanager.app and all links now point there. Old links redirect, so existing installs keep working.

= 2026.07.4 =
Recommended update. Fixes "Force From Address" incorrectly rewriting mail from other plugins, batches the weekly digest so large lists can't stall it, cuts memory use on large CSV/XLSX imports, and moves newsletter tracking off wp_posts onto dedicated tables.

= 2026.07.3 =
Recommended update. Fixes recurring Stripe donations (previously charged once instead of on a schedule), adds donor confirmation messages and emails, stops abandoned checkouts from being logged as donations, and closes a few security and performance gaps.

= 2026.07.2 =
Adds five editor blocks: email signup, unsubscribe, donation form, social share, and contact form. Each is also a shortcode. Includes new visitor social-share buttons and a contact form.

= 2026.07.1 =
Maintenance update: a PHP 8.1 fix, an optional attribution link (off by default), and a dismissible review reminder.

= 2.1.0 =
Adds one-click unsubscribe and List-Unsubscribe headers for better deliverability, default form styles, a setup health check, and a CAN-SPAM postal address setting. Fixes unsubscribe-link resolution and the Sentry version tag.

= 2.0.0 =
Major update: Stripe payments for free users, social sharing, subscriber preferences, convert-to-event, and newsletter click tracking. Pro adds license system, recurring donations, custom fields, automation, segmentation, and import tools.

= 1.1.3 =
Feature update with improved UI, membership summary tables, and better navigation. Recommended for all users.
