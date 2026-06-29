=== Nonprofit Manager ===
Contributors: eric1985
Tags: nonprofit, donations, membership, email, events
Requires at least: 6.0
Tested up to: 6.8.3
Stable tag: 2026.06.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage memberships, donations, newsletters, and events from WordPress.

== Description ==

Nonprofit Manager runs your members, donations, newsletters, and events from WordPress. It fits a small community group or a larger nonprofit, and the core tools are free.

**Core Features:**

* **Membership Management** - Track members, manage membership levels, and keep your community organized
* **Donation Processing** - Accept one-time donations with PayPal, Venmo, and Stripe
* **Email Newsletters** - Build and send email campaigns in the Gutenberg block editor
* **Event Calendar** - Manage and promote nonprofit events with an integrated calendar
* **Social Sharing** - Auto-share new posts and events to Facebook and X (Twitter)
* **Subscriber Preferences** - Let subscribers choose instant notifications or weekly digests
* **Contact Forms** - Customizable membership signup and donation forms
* **CAPTCHA Protection** - Support for Cloudflare Turnstile and Google reCAPTCHA

**Email Newsletter Features:**

* Gutenberg-powered email template builder
* Reusable email headers and footers
* Newsletter templates with [email_content] placeholder
* Send test emails before publishing
* Email tracking (opens and clicks)
* Rate limiting to prevent server overload
* CAN-SPAM compliance footer

**Payment Gateways:**

* PayPal (Email Link & Smart Button SDK)
* Venmo
* Stripe (one-time donations)
* Recurring donations (Pro)

**Social Sharing (New in 2.0):**

* Auto-share new posts and events to connected social networks
* Free: Facebook Pages and X (Twitter)
* Pro: adds Reddit, Bluesky, Mastodon, Threads, and Nextdoor
* Customizable share format with {title}, {url}, {excerpt} placeholders

**Subscriber Notification Preferences (New in 2.0):**

* New post email notifications (instant or weekly digest)
* New event email notifications (instant or weekly digest)
* Subscriber self-service preference management page
* Automatic weekly digest emails with recent posts and events

**Pro Features:**

[Nonprofit Manager Pro](https://nonprofitmanager.ericrosenberg.com/pricing) adds:

* 6 email providers (AWS SES, Brevo, SendGrid, Mailgun, Postmark, SparkPost)
* Recurring donation management with Stripe subscriptions
* Custom member fields (text, dropdown, checkbox, date, etc.)
* Email automation workflows (welcome emails, donation receipts, expiry reminders)
* Advanced member segmentation with AND/OR condition builder
* Import members from Mailchimp, Constant Contact, CSV, XLSX, or Google Sheets
* Social sharing to Reddit, Bluesky, Mastodon, Threads, and Nextdoor
* Guided email provider setup wizard
* Priority support

== Installation ==

1. Upload the `nonprofit-manager` folder to `/wp-content/plugins/` or install via the WordPress Plugin Directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Follow the setup wizard to choose which features to enable.
4. Configure your payment gateways, email settings, and membership levels.

== Frequently Asked Questions ==

= What payment gateways are supported? =

The free version supports PayPal, Venmo, and Stripe for one-time donations. Recurring donations via Stripe are available with Nonprofit Manager Pro.

= Can I send email newsletters? =

Yes. The built-in newsletter system uses the Gutenberg editor for composing emails, supports reusable templates, and includes open and click tracking.

= Does it work with my theme? =

Nonprofit Manager is designed to work with any properly coded WordPress theme. Forms and shortcodes adapt to your theme's styling.

= Can I convert existing posts into events? =

Yes. Version 2.0 adds a "Convert to Event" action on any post or page. It creates an event with the same content and lets you set the date, time, and location.

= How do I import my existing email list? =

With Nonprofit Manager Pro, go to Nonprofit Manager > Import. You can import from CSV, XLSX, Google Sheets, Mailchimp, or Constant Contact, and it auto-detects your columns.

= Where can I get support? =

Free support is available through the WordPress.org support forums. Pro customers receive priority support via email at support@ericrosenberg.com.

== Screenshots ==

1. Dashboard overview showing membership and donation statistics
2. Member management interface with filtering and bulk actions
3. Email newsletter editor with Gutenberg blocks
4. Newsletter template builder with header/footer support
5. Donation form with PayPal, Venmo, and Stripe options
6. Event calendar management interface
7. Social sharing settings with connected accounts
8. Subscriber notification preference management

== Changelog ==

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

= 2.1.0 =
Adds one-click unsubscribe and List-Unsubscribe headers for better deliverability, default form styles, a setup health check, and a CAN-SPAM postal address setting. Fixes unsubscribe-link resolution and the Sentry version tag.

= 2.0.0 =
Major update: Stripe payments for free users, social sharing, subscriber preferences, convert-to-event, and newsletter click tracking. Pro adds license system, recurring donations, custom fields, automation, segmentation, and import tools.

= 1.1.3 =
Feature update with improved UI, membership summary tables, and better navigation. Recommended for all users.
