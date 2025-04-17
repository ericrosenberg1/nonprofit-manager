=== Nonprofit Manager ===
Contributors: eric1985  
Tags: nonprofit, donation, members, email, newsletters  
Requires at least: 6.0  
Tested up to: 6.7
Requires PHP: 7.4  
Stable tag: 1.0.0
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

A full-featured WordPress plugin to help nonprofits manage:
- Member roles and directories
- Email lists and campaigns
- Donations (Stripe, PayPal, Square)
- Events and volunteers

== Description ==

Nonprofit Manager helps your organization grow, engage, and raise funds with tools tailored for nonprofits.

== Installation ==

1. Upload the plugin to your `/wp-content/plugins/` directory.
2. Activate via Plugins > Installed Plugins.
3. Navigate to the “Nonprofit Manager” section in your dashboard.

== Setup ==

- Configure **Transactional Email** under Nonprofit Manager > Email Settings.
- Connect **Payment Gateways** under Nonprofit Manager > Payment Settings.
- Use shortcodes to embed forms:
  - `[np_email_signup]`
  - `[np_email_unsubscribe]`
  - `[np_donation_form]`
  - `[np_volunteer_form]`
  - `[np_event_rsvp]`
  - `[np_member_directory]`

== Gutenberg Email Composer ==

Use the “Email Composer” block to create and queue messages directly in the block editor.

== Security Notes ==

- All inputs are sanitized and validated.
- Sensitive keys stored securely in the `wp_options` table.
- Background tasks like email delivery run safely via WP-Cron.

== Frequently Asked Questions ==

**Can I use my own email service?**  
Yes! You can choose between Amazon SES, Mailgun, SMTP, and more.

**Can I customize the donation form?**  
Yes, form text, labels, payment methods, and minimums are all editable.

== Screenshots ==

1. Email composer using Gutenberg.
2. Member management dashboard.
3. Donation settings with PayPal and Stripe.

== Changelog ==

= 1.0.0 =
* Initial release of Nonprofit Manager.

== Upgrade Notice ==

= 1.0.0 =
First public release. Includes email composer, donation support, member tools, and block-based forms.

== License ==

This plugin is licensed under the GPLv2 or later. You are free to use, modify, and redistribute it under the terms of that license.

== Author ==

Eric Rosenberg – [https://ericrosenberg.com](https://ericrosenberg.com)
