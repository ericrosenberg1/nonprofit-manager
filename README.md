# Nonprofit Manager

[![WordPress.org version](https://img.shields.io/wordpress/plugin/v/nonprofit-manager?label=wordpress.org)](https://wordpress.org/plugins/nonprofit-manager/)
[![Downloads](https://img.shields.io/wordpress/plugin/dt/nonprofit-manager)](https://wordpress.org/plugins/nonprofit-manager/advanced/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/nonprofit-manager)](https://wordpress.org/plugins/nonprofit-manager/)
[![Requires PHP](https://img.shields.io/wordpress/plugin/required-php/nonprofit-manager)](https://wordpress.org/plugins/nonprofit-manager/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

Nonprofit Manager is a free WordPress plugin that runs a small nonprofit's members, donations, newsletters and events from one place. Install it from [WordPress.org](https://wordpress.org/plugins/nonprofit-manager/), and read more at [nonprofitmanager.app](https://nonprofitmanager.app/).

This repo is the free plugin, the same code that ships on WordPress.org. [Nonprofit Manager Pro](https://nonprofitmanager.app/pricing) is a separate paid add-on and isn't in this repo.

## Who it's for

Small nonprofits, clubs, churches, neighborhood councils, PTAs and HOAs that already run WordPress. Most of them would rather not bolt together a membership plugin, a donation plugin, a newsletter plugin and an events plugin. One contact list sits under all four here. Open any donor and you see their membership, their gifts and the newsletters they received.

Nonprofit Manager runs the website of the College Area Community Council in Ventura, California. Every council meeting since January 2022 is on that site as an event.

## What the free plugin does

**Members and donors**

- One contact list for members and donors, with membership levels, filters, bulk actions and lifetime giving per person.
- Signup and unsubscribe forms as blocks or shortcodes, protected by Cloudflare Turnstile or Google reCAPTCHA.
- Imports from CSV, XLSX, Google Sheets, Mailchimp and Constant Contact. The free plugin imports 50 contacts per job.
- A Membership Manager role, so a volunteer can log in and work with members, donations and newsletters without seeing your settings.

**Donations**

- One-time card donations through Stripe, recorded in WordPress so they show in your donor list and totals.
- PayPal and Venmo donate buttons. These send the donor to PayPal or Venmo to pay, and the gift is not recorded in WordPress.
- 16 currencies: USD, CAD, GBP, EUR, AUD, NZD, CHF, SEK, NOK, DKK, JPY, MXN, SGD, HKD, PLN and CZK. Venmo is US dollars only.
- A donation form block or shortcode for any page, with a thank-you message.
- No per-contact fee and no percentage taken from donations. You pay only your payment processor's rates.

**Newsletters**

- Write newsletters in the block editor with reusable templates, headers and footers.
- Open and click tracking in their own tables, so your posts table stays clean.
- Test sends, rate limiting for large lists, a CAN-SPAM footer and RFC 8058 one-click unsubscribe headers.
- New-post and new-event notifications, sent at once or in a weekly digest. Each subscriber controls their own preferences.

**Events**

- A calendar with Month, Week and List views, plus Calendar and Upcoming Events blocks.
- An iCal feed, quick-add from the dashboard, and one-click conversion of any post or page into an event.

**Sharing**

- Auto-share new posts and events to Facebook and X.
- Share buttons and a contact form, each as a block or shortcode.

Nothing in the free plugin expires, and no screen is locked behind a trial.

## Screenshots

![Contacts and members list with filters, lifetime value and last donation](https://ps.w.org/nonprofit-manager/assets/screenshot-2.png)

![Newsletter composer in the block editor with audience selection](https://ps.w.org/nonprofit-manager/assets/screenshot-3.png)

All eight screenshots are on the [WordPress.org listing](https://wordpress.org/plugins/nonprofit-manager/#screenshots).

## Install

From your WordPress dashboard: Plugins, Add New, search for "Nonprofit Manager", then Install and Activate. The setup wizard opens on first activation and lets you turn on only the parts you need.

You can also [download the zip](https://nonprofitmanager.app/download/) and upload it under Plugins, Add New, Upload Plugin.

Requires WordPress 6.0 or newer and PHP 8.1 or newer.

To build the zip from this repo, run `./build.sh`. The script packages committed files only and leaves out the tests, the hooks and this README.

## What Pro adds

[Nonprofit Manager Pro](https://nonprofitmanager.app/pricing) starts at $47 a year for one site and adds:

- Recurring donations.
- Automatic membership dues billing.
- Email automations such as welcome emails, donation receipts and expiry reminders.
- Custom member fields and segments built with AND/OR conditions.
- PayPal Smart Buttons with a verification record for every capture.
- Sending through AWS SES, Brevo, SendGrid, Mailgun or Postmark.
- Imports with no 50-contact cap.
- Auto-share to Reddit, Bluesky, Mastodon, Threads and Nextdoor.

Pro is a separate plugin that needs the free one installed. The two release on the same version number.

## Free tools and research on the site

- [Documentation](https://nonprofitmanager.app/docs): installation, quick start guides for members, donations, newsletters and events, and an FAQ.
- [Pricing](https://nonprofitmanager.app/pricing): what's free, what's Pro, and the Pro plans.
- [Donation fee calculator](https://nonprofitmanager.app/donation-fee-calculator/): what Donorbox, Givebutter, Zeffy, GiveWP, PayPal and Nonprofit Manager take from a gift, using each provider's published rates.
- [Nonprofit templates](https://nonprofitmanager.app/nonprofit-templates/): donation receipts that follow IRS Publication 1771, a membership renewal email and meeting minutes.
- [Compare](https://nonprofitmanager.app/compare/): side-by-side pages against Donorbox, GiveWP, Wild Apricot, MemberPress and others.
- [How small nonprofits take donations online](https://nonprofitmanager.app/small-nonprofit-website-study/): a September 2026 study of 3,351 small nonprofit websites.
- [Setup and hosting services](https://nonprofitmanager.app/services): setup for $499, hosting from $49 a month.

## Support and bug reports

- Questions about the free plugin go to the [WordPress.org support forum](https://wordpress.org/support/plugin/nonprofit-manager/).
- Bugs and feature requests: [open an issue here](https://github.com/ericrosenberg1/nonprofit-manager/issues). Include your WordPress and PHP versions and the steps to reproduce.
- Security problems: email support@nonprofitmanager.app instead of opening a public issue. See [SECURITY.md](.github/SECURITY.md).
- Pro customers get email support at support@nonprofitmanager.app.

If the plugin is working for you, a [review on WordPress.org](https://wordpress.org/support/plugin/nonprofit-manager/reviews/#new-post) helps other small nonprofits find it.

## Development

The plugin has no build step and no Composer dependencies. Clone it into `wp-content/plugins/nonprofit-manager` and activate.

Tests run without WordPress. They cover the logic that can stand alone: currency handling, dashboard totals, the Membership Manager role, Stripe and PayPal verification, the setup wizard redirect.

```bash
php tests/test-currency.php   # one suite
scripts/ci-gates.sh           # php -l on every file, every suite, then the release lockstep check
```

Turn the hooks on once per clone so the same gates run before each push:

```bash
git config core.hooksPath .githooks
```

There are no GitHub Actions here on purpose. The pre-push hook is the gate.

A few conventions:

- `readme.txt` is the WordPress.org listing and the changelog. This file is for GitHub and never ships in the zip (`.gitattributes` marks it `export-ignore`).
- Versions are `YYYY.MM.N`. Free and Pro ship on the same number.
- Pull requests are welcome for bug fixes. Open an issue first for anything bigger so we can talk it through.

## License

GPLv2 or later. See [LICENSE](LICENSE).

Built by [Eric Rosenberg](https://ericrosenberg.com) at Rosenberg Digital LLC.
