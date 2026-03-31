=== Nonprofit Manager ===
Contributors: eric1985
Tags: nonprofit, donations, membership, email, events
Requires at least: 6.0
Tested up to: 6.8.3
Stable tag: 2.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Comprehensive nonprofit management solution for memberships, donations, newsletters, and events.

== Description ==

Nonprofit Manager is an all-in-one solution designed to help nonprofit organizations manage their operations directly from WordPress. Whether you're running a small community organization or a larger nonprofit, this plugin provides the essential tools you need to succeed.

**Core Features:**

* **Membership Management** - Track members, manage membership levels, and keep your community organized
* **Donation Processing** - Accept one-time donations with PayPal, Venmo, and Stripe
* **Email Newsletters** - Create and send beautiful email campaigns with Gutenberg block editor
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

Upgrade to [Nonprofit Manager Pro](https://nonprofitmanager.ericrosenberg.com/pricing) for advanced capabilities:

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

With Nonprofit Manager Pro, go to Nonprofit Manager > Import. You can import from CSV, XLSX, Google Sheets, Mailchimp, or Constant Contact with smart column auto-detection.

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

= 2.0.0 =
Major update: Stripe payments for free users, social sharing, subscriber preferences, convert-to-event, and newsletter click tracking. Pro adds license system, recurring donations, custom fields, automation, segmentation, and import tools.

= 1.1.3 =
Feature update with improved UI, membership summary tables, and better navigation. Recommended for all users.
