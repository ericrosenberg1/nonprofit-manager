=== Nonprofit Manager – Donations, Membership, Newsletters & Events ===
Contributors: eric1985
Tags: nonprofit, donations, membership, fundraising, newsletter
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 2026.09.4
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
* Drop a donation form on any page as a block or shortcode, complete with thank-you messages and donor confirmation emails
* Every PayPal capture keeps a server-side verification record, so your books always have something to check against
* Recurring donations*
* Membership dues auto-billing*

**Newsletters and email**

* Write newsletters in the editor you already know (Gutenberg), with reusable templates, headers, and footers
* See who opens and clicks, tracked in dedicated tables that won't bloat your database
* Send yourself a test first, let rate limiting pace large sends, and stay CAN-SPAM compliant
* One-click unsubscribe with RFC 8058 List-Unsubscribe headers, which Gmail and Yahoo now expect
* Tell subscribers about new posts and events instantly or in an automatic weekly digest, and let them pick their own preferences
* Email automation workflows: welcome emails, donation receipts, and expiry reminders*
* Send through AWS SES, Brevo, SendGrid, Mailgun, Postmark, or SparkPost*

**Events**

* Event calendar with Month, Week, and List views, plus Calendar and Upcoming Events blocks for any page
* iCal feed, quick-add from the dashboard, and one-click convert of any post or page into an event

**Sharing and outreach**

* Auto-share new posts and events to Facebook and X, with {title}, {url}, {excerpt} placeholders
* Auto-share to Reddit, Bluesky, Mastodon, Threads, and Nextdoor*
* Give visitors share buttons and a contact form, each as a block or shortcode

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

Go to Nonprofit Manager > Import and choose CSV, XLSX, Google Sheets, Mailchimp, or Constant Contact. The importer detects your columns for you. The free plugin imports up to 50 supporters per job, and Pro removes the cap.

= Where can I get support? =

Ask in the WordPress.org support forums and we'll help. Pro customers also get priority email support at support@nonprofitmanager.app.

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

= 2026.09.4 =
* Fixed: The bundled form styles did not load on a page whose only form was the Pro membership join form, so that form and the confirmation banner a new member sees after paying both rendered unstyled. Any page using it now gets the same styling as the other forms.
* Added: Developers can extend which shortcodes load the form styles with the new npmp_form_style_shortcodes filter.

= 2026.09.3 =
* Housekeeping: Lockstep with Nonprofit Manager Pro 2026.09.3, which hardens the one-time (lifetime) membership dues option Pro added in 2026.09.1. No changes to the free plugin itself.

= 2026.09.2 =
* Fixed: A member's donation history listed a single donation as "One_time" instead of "One-time".
* Housekeeping: Back in lockstep with Nonprofit Manager Pro, which skipped ahead to 2026.09.1 on its own for a new one-time membership dues option. Pro 2026.09.2 fixes the Monthly Recurring Revenue total, which counted a once-a-year subscription as if it billed every month.

= 2026.08.4 =
* Security: If you accept PayPal donations but have not saved a PayPal API secret, the plugin now warns you in the admin. Without the secret, donations cannot be checked against PayPal before they are recorded, which means a fake donation record can be submitted without a real payment behind it. Genuine donations are still recorded either way, so nothing is lost by leaving it as is, but adding the secret in Payment Settings turns verification on.

= 2026.08.3 =
* Fixed: Sharing a post or event to X (formerly Twitter) no longer fails silently. The API request was signed with the wrong OAuth method, so every X share was rejected.
* Fixed: Calendar event times could display shifted by your site's UTC offset (e.g. an event entered as 10:00 AM showing as a different hour). Event times are now parsed against your site's configured timezone.
* Fixed: The "Default Level" setting for new email signups was being ignored; new signups always got a generic "member" label regardless of what you configured.
* Fixed: An unparsable date in a member record could get silently saved as January 1, 1970 instead of being left blank.
* Fixed: Clicking a tracked link in a newsletter that points off-site (a donation processor, a social profile) could redirect to your homepage instead of the real destination. This applies to newsletters sent from version 2.0.0 onward, where the link's destination is signed into the tracking token. Links from older newsletters still resolve to your homepage rather than an external site, deliberately: those tokens don't identify a destination, so honoring an arbitrary one would let anyone holding an old link bounce visitors off your domain to a site of their choosing.
* Fixed: The "All Members" checkbox on the newsletter recipient picker could lose its checked state after saving.
* Fixed: Donation amounts with certain cents values (like $19.99) could be undercharged by a cent due to floating-point rounding.
* Fixed: The "Annual Recurring Donations" total on the dashboard counted only one of the two ways a once-a-year donation is recorded, so part of your annual recurring revenue showed as $0. Both are now counted.
* Security: Closed a gap where a logged-out visitor could submit a fake donation record (and trigger a thank-you email) to the donation-logging endpoint without an actual PayPal payment behind it. Note that donations are only checked against PayPal when a PayPal API secret is saved in Payment Settings. Without one, verification is skipped so that existing setups keep working, and this gap remains open. If you accept PayPal donations, adding the secret is worth doing.
* Security: Added missing capability checks on three admin settings-save handlers (General Settings, Feature toggles, Social Sharing) that previously relied on a nonce alone.
* Improved: Sending a newsletter to a large recipient list now queues in batches instead of one database write per recipient.
* Improved: The member-tier counts on the Membership dashboard run a cheaper query.
* Housekeeping: Tested against WordPress 7.1-RC3 on PHP 8.5, where the plugin activates and runs with no deprecation warnings or notices. Raised the minimum required PHP to 8.1 and closed several PHP 8.1+ deprecation warnings found along the way (a couple of which would fatal on newer PHP given specific malformed input). Kept in lockstep with Nonprofit Manager Pro 2026.08.3, which received a matching security, bug-fix, and performance pass.

= 2026.08.2 =
* Housekeeping: Version bump to stay in lockstep with Nonprofit Manager Pro 2026.08.2, which adds a clear warning banner when Pro isn't activated with a valid license key or the two plugins' versions don't match. No changes to the free plugin itself.

= 2026.08.1 =
* Changed: Nonprofit Manager has a new home at [nonprofitmanager.app](https://nonprofitmanager.app/). Upgrade, account, and support links throughout the plugin now point there. Old links redirect, so nothing breaks on existing installs.
* Improved: Refreshed the plugin listing with a single comprehensive feature list covering both free and Pro.
* Housekeeping: Kept in lockstep with Nonprofit Manager Pro 2026.08.1, which moves license activation and updates to the new domain.

= 2026.07.5 =
* Fixed: The Social Sharing settings page no longer shows a "Sorry, you are not allowed to access this page" error for administrators. It was a menu load-order problem, not a permissions one, so the page now opens normally.
* Improved: Large member imports (CSV, Excel, Google Sheets, and Constant Contact) now process in small batches in the background instead of all in one request, so a big import can't time out partway through and leave the job half finished.
* Improved: Sending a newsletter now records its send queue in a dedicated database table instead of creating a hidden post for every recipient, so sending to a large list no longer bloats your site's database. This matches the open and click tracking change from the previous release.

= 2026.07.4 =
* Fixed: Turning on "Force From Address" no longer rewrites the From address on mail sent by other plugins or by WordPress itself (password resets, new-user notifications, and so on). It now only affects mail this plugin sends.
* Improved: PayPal donations now keep a full server-side verification record for every capture, including ones where the payment couldn't be verified, so you have something to check a donor's payment against if a record ever looks off.
* Improved: The weekly subscriber digest now sends in small batches in the background instead of mailing your whole list in one run, so a large subscriber list can't cause it to stall out partway through.
* Improved: Large CSV and XLSX member imports use far less memory during upload preview and processing. A 100,000-row file that previously needed around 40MB of memory just to preview now needs about 2MB.
* Improved: Newsletter open and click tracking now writes to dedicated database tables instead of creating a post for every open and click, so tracking a growing list no longer bloats your site's database over time.

= 2026.07.3 =
* Fixed: Choosing a recurring frequency (weekly, monthly, quarterly, annual) on the Stripe donation form now actually creates a recurring Stripe subscription. It previously charged a single one-time payment no matter which frequency was selected.
* Fixed: Donors who paid by Stripe now return to the page they gave from and see a real thank-you or cancellation message, and receive a confirmation email. Both previously landed on the homepage with no acknowledgment.
* Fixed: A donation is now recorded only after Stripe confirms payment. Previously, starting checkout was enough to log a completed donation, so abandoned or cancelled checkouts inflated donation totals and reports.
* Fixed: The PayPal Smart Buttons donation form loads correctly. It previously tried to render before the PayPal script had loaded and could fail silently.
* Fixed: The email unsubscribe form now sends a confirmation link instead of unsubscribing an email address immediately, so a submitted address can't be used to unsubscribe someone else.
* Fixed: Stored API keys and CAPTCHA secret keys are no longer displayed back in the settings screens. Leave a key field blank when saving to keep the existing value.
* Fixed: Newsletter click-tracking links can no longer be altered to point somewhere other than the original link.
* Fixed: Custom member fields, the newsletter segment picker, and signup notification preferences (all Pro features) now actually appear where they're supposed to. A wiring gap kept them from rendering even when configured.
* Fixed: New-post and new-event email notifications to subscribers no longer run while you're publishing, which could slow down or time out the Publish button on a large list.
* Improved: Removed a legacy, unused donation-form script that could interfere with the PayPal button.
* Improved: Faster admin dashboard and member list pages, especially on larger contact lists.
* Improved: The public event calendar feed (iCal) loads faster on repeat requests.
* Removed: The "Event Registration" upgrade notice and Registrations column, which referenced a feature that isn't available.

= 2026.07.2 =
* Added: Five editor blocks for member and donor content: Email Signup, Email Unsubscribe, Donation Form, Social Share, and Contact Form. Each is also a shortcode, so you can drop them in with the block inserter or with a shortcode like [npmp_social_share] or [npmp_contact_form].
* Added: Visitor social sharing. The Social Share block and [npmp_social_share] shortcode add Facebook, X, LinkedIn, Reddit, email, and copy-link buttons that share the current page, and you choose which networks to show.
* Added: A general contact form. The Contact Form block and [npmp_contact_form] shortcode collect a name, email, optional subject, and message, protected by a honeypot and your configured CAPTCHA, and deliver to your site admin email (filterable with npmp_contact_form_recipient).

= 2026.07.1 =
* Fixed: Resolved a PHP 8.1+ "strip_tags(): Passing null" warning on the hidden Setup screen by setting the admin page title before the header renders.
* Added: Optional "Powered by Nonprofit Manager" link for donation forms and newsletter emails. Off by default. Turn it on under Nonprofit Manager > General Settings to help other nonprofits find the plugin.
* Added: An occasional, dismissible review reminder in the admin after your first recorded donation or sent newsletter, with a direct option to send private feedback instead.
* Housekeeping: Refreshed the plugin listing details and version alignment.

= 2026.06.4 =
* Maintenance release. The version is kept in lockstep with Nonprofit Manager Pro, which adds a local and development license bypass so developers can run Pro on localhost without remote activation. No changes to the free plugin.

= 2026.06.3 =
* Added: Redesigned events calendar with Month, Week, and List views and a navigation toolbar (Today, previous/next, and year jumps), plus a clean, responsive front-end stylesheet
* Added: Calendar display options on the Calendar Settings screen (default view, highlight color, event times, list length, show past events); the grid follows your WordPress "Week starts on" setting
* Added: Events Calendar and Upcoming Events blocks for the WordPress editor, so you can drop a calendar or event list onto any page with Month, Week, List, and category options
* Fixed: The calendar no longer renders twice on the configured calendar page
* Added: "Edit Event" button in the WordPress admin toolbar on single event pages, matching the default behavior for posts and pages
* Changed: Slimmed the plugin by removing dead code, unused helper functions, and a non-functional block registration (the [npmp_donation_form], [npmp_email_signup], and [npmp_email_unsubscribe] shortcodes are unchanged)
* Performance: PayPal SDK now loads only on pages that show a donation form instead of site-wide
* Fixed: Events added from the dashboard quick-add now appear on the calendar (correct date format)
* Fixed: Members added from the dashboard quick-add now use the correct subscriber status

= 2026.06.2 =
* Added: Member import wizard (CSV, XLSX, Google Sheets, Mailchimp, Constant Contact) and a guided onboarding tour, brought into the main plugin line
* Changed: Version numbering realigned with the WordPress.org listing; free and Pro now ship in lockstep
* Added: One-click unsubscribe with RFC 8058 List-Unsubscribe headers on newsletters, post/event notifications, and the weekly digest for better Gmail and Yahoo inbox placement
* Added: Default front-end stylesheet for the signup, unsubscribe, preferences, and donation forms (turn it off with the npmp_enable_default_form_styles filter)
* Added: Setup status check on the Membership Settings screen that flags a missing or form-less unsubscribe page
* Added: Organization mailing address setting so the CAN-SPAM footer shows a real postal address
* Added: Unsubscribe page is created automatically on activation
* Fixed: [unsubscribe_url] now resolves to your configured unsubscribe page instead of a hardcoded /unsubscribe link
* Fixed: CAN-SPAM footer [address] uses your postal mailing address instead of the site admin email
* Fixed: Sentry events are tagged with the real plugin version instead of "unknown"
* Changed: Cleaned up admin and marketing copy; corrected the README version and shortcode list

= 2.0.1 =
* Changed: Pro features now work when Pro plugin is installed (license required for updates only)
* Fixed: Class declaration conflict when upgrading Pro plugin

= 2.0.0 =
* Added: Stripe payment gateway for free users (one-time donations)
* Added: Social sharing module - auto-share posts and events to Facebook and X (Twitter)
* Added: Subscriber notification preferences (instant or weekly digest for new posts/events)
* Added: Convert any post or page to a calendar event with one click
* Added: Click tracking for newsletter links (previously "coming soon")
* Added: Manage preferences page with HMAC-secured subscriber links
* Added: Weekly digest cron for automatic summary emails
* Improved: Newsletter tracking now uses HMAC tokens instead of expiring nonces (links work indefinitely)
* Improved: Stripe checkout now includes security nonce in multi-gateway form
* Improved: Upgrade URL now points to nonprofitmanager.ericrosenberg.com
* Security: Fixed missing nonce in multi-gateway Stripe AJAX call
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
* Added: Plugin action links (Overview, Developer, Support) for easy access
* Added: Membership and Donations summary tables on main overview page
* Improved: Main page layout - feature activation box now auto-sizes to content
* Improved: Membership Settings page now includes membership levels management
* Changed: "Membership Forms" renamed to "Membership Settings" for clarity
* Updated: Tested up to WordPress 6.8.3

= 1.1.2 =
* Fixed: Dashboard widget member count now displays accurate data using correct meta key

= 1.1.1 =
* Fixed: Venmo payment button now uses proper deep link protocol with fallback to profile page

= 1.1 =
* Added: Newsletter template system with Gutenberg editor
* Added: "Send to All Members" option for newsletters
* Added: Version mismatch warning for Pro users
* Added: Admin helper functions for consistent UI
* Improved: Security - verified all nonce checks and sanitization
* Improved: Performance - optimized member counting queries
* Improved: UI consistency across all admin pages
* Fixed: Newsletter audience selection and tracking
* Fixed: PayPal button rendering issues

= 1.0.2 =
* Fixed: AWS SES validation for email delivery
* Fixed: PayPal/Venmo button display issues
* Fixed: Dashboard widget member counts
* Improved: Email delivery error handling

= 1.0.1 =
* Fixed: Activation hooks for better compatibility
* Improved: Setup wizard flow
* Added: Better error messages for payment processing

= 1.0.0 =
* Initial release
* Membership management system
* Donation processing (PayPal, Venmo)
* Basic email newsletter functionality
* Event calendar
* Setup wizard

== Upgrade Notice ==

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
