=== Nonprofit Manager ===
Contributors: eric1985  
Tags: nonprofit, donation, members, email, newsletters  
Requires at least: 6.0  
Tested up to: 6.8
Requires PHP: 7.4  
Stable tag: 1.0.0
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

A full-featured plugin for nonprofits to manage members, emails, donations, and events.

== Description ==

Nonprofit Manager helps your organization grow, engage, and raise funds with tools tailored for nonprofits.

== Installation ==

1. Upload the plugin to your `/wp-content/plugins/` directory.
2. Activate via Plugins > Installed Plugins.
3. Navigate to the "Nonprofit Manager" section in your dashboard.

== Setup ==

- Configure **Transactional Email** under Nonprofit Manager > Email Settings.
- Connect **Payment Gateways** under Nonprofit Manager > Payment Settings.
- Use shortcodes to embed forms:
  - `[npmp_email_signup]`
  - `[npmp_email_unsubscribe]`
  - `[npmp_donation_form]`
  - `[npmp_volunteer_form]`
  - `[npmp_event_rsvp]`
  - `[npmp_member_directory]`

== Gutenberg Email Composer ==

Use the "Email Composer" block to create and queue messages directly in the block editor.

== Security Notes ==

- All inputs are sanitized and validated.
- Sensitive keys stored securely in the `wp_options` table.
- Background tasks like email delivery run safely via WP-Cron.

== External Services ==

This plugin may connect to external services depending on your configuration:

= Cloudflare Turnstile =

**What it does:** Provides CAPTCHA-style bot protection on public-facing forms using Cloudflare's Turnstile service.

**Data transmitted:**
- When a protected form is viewed: Turnstile's JavaScript widget loads from Cloudflare's CDN at `challenges.cloudflare.com`.
- When the form is submitted: the Turnstile response token, the visitor's IP address (if available), and your configured Turnstile site/secret keys are sent to Cloudflare's verification endpoint to validate the interaction.

**When it's used:**
- Only if you select Turnstile as the CAPTCHA provider and supply valid site/secret keys.
- Only on forms where you enable bot protection inside the plugin settings.

**Service provider:**
- [Cloudflare Terms of Service](https://www.cloudflare.com/terms/)
- [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/)

= PayPal Payment Gateway =

**What it does:** Allows your nonprofit to accept donations via PayPal.

**Data transmitted:**
- When the donation form is loaded: PayPal SDK is loaded from PayPal's servers
- When a donation is processed: Donor's email and donation amount are sent to PayPal for payment processing
- The PayPal Client ID (configured in your settings) is used to identify your PayPal business account

**When it's used:**
- Only when you enable the PayPal payment gateway in the plugin settings
- Only on pages where the donation form appears

**Service provider:**
- [PayPal Terms of Service](https://www.paypal.com/us/webapps/mpp/ua/servicedescription-full)
- [PayPal Privacy Policy](https://www.paypal.com/us/legalhub/privacy-full)

No data is sent to PayPal until the user actively initiates a donation.

= Amazon SES (optional email delivery) =

**What it does:** Sends transactional and marketing emails through Amazon Simple Email Service (SES) via SMTP.

**Data transmitted:**
- When SES is selected as the email provider: the SMTP username, password, and selected AWS region are stored in WordPress options and used to authenticate with Amazon's SMTP endpoint (`email-smtp.<region>.amazonaws.com`).
- Whenever an email is sent: recipient addresses, sender information, subject lines, email content, and metadata required by the SMTP protocol are transmitted to Amazon SES for delivery.

**When it's used:**
- Only if you choose "Amazon SES (SMTP credentials)" in Email Settings and provide valid SES credentials.

**Service provider:**
- [AWS Service Terms](https://aws.amazon.com/service-terms/)
- [AWS Privacy Notice](https://aws.amazon.com/privacy/)

== Frequently Asked Questions ==

**Can I use my own email service?**  
Yes! You can choose any SMTP mailer, and more service integreations are planned.

**Can I customize the donation form?**  
Yes, form text, labels, payment methods, and minimums are all editable.

== Screenshots ==

1. Email composer using Gutenberg.
2. Member management dashboard.
3. Donation settings with PayPal.

== Changelog ==

= 1.0.0 =
* Initial release of Nonprofit Manager.

== Upgrade Notice ==

= 1.0.0 =
First public release. Includes email composer, donation support, member tools, and block-based forms.

== License ==

This plugin is licensed under the GPLv2 or later. You are free to use, modify, and redistribute it under the terms of that license.

== Author ==

Eric Rosenberg - [https://ericrosenberg.com](https://ericrosenberg.com)
