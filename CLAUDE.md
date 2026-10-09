# Nonprofit Manager (free plugin)

A WordPress plugin for small nonprofits. The free tier is on wordpress.org, Pro is sold through
the licence server at https://nonprofitmanager.app. This repo is the source of truth for the free
code and the wp.org listing. Product history lives in memory `project_nonprofit_manager` and
MemPalace (wing `handoffs`). Growth work runs from `~/Code/nonprofit-manager-growth/growth-queue.md`.

## Where things live

    ~/Code/nonprofit-manager                         free plugin (ericrosenberg1/nonprofit-manager, public)
    ~/Code/nonprofit-manager-pro                     Pro (ericrosenberg1/nonprofit-manager-pro), ships only through the licence server
    ~/Code/nonprofit-manager/nonprofit-manager-site  licence server + sales site, its own private repo, git-ignored here
    ~/wp-svn/nonprofit-manager-svn                   wp.org SVN working copy (trunk/, tags/, assets/), cached credentials
    ~/Code/nonprofit-manager-growth                  growth queue, link-scout ledger, drafts (not in git: contact addresses)

- Live test site, cloudpanel: `ssh cloudpanel 'sudo -n -u nonprofitmgr -- wp --path=/home/nonprofitmgr/htdocs/nonprofitmanager.ericrosenberg.com ...'`
  (that is also the WordPress half of nonprofitmanager.app).
- CACC (caccventura.com) is the longest-running live install, free + Pro: `ssh cacc`, `/var/www/html`,
  run wp-cli as `sudo -u www-data wp ...`. Read-only unless the task says otherwise.
- Free covers contacts, one-time Stripe donations, PayPal and Venmo buttons, newsletters, weekly
  digest, events with iCal, imports, share and contact blocks, the Membership Manager role.
  Pro adds dues auto-billing, recurring Stripe donations, the PayPal API gateway, custom fields,
  segmentation, automations, chunked imports, more email providers.

## Product facts that keep leaking into copy wrong

- **Free records Stripe gifts only.** Its PayPal and Venmo buttons send donors off-site and record
  nothing. Recording PayPal gifts is Pro's PayPal API gateway.
- 16 currencies (`npmp_currency`, helpers in `includes/npmp-currency.php`). Venmo is USD only.
- `NPMP_PRO_MIN_FREE_VERSION` was deliberately not raised for currencies. Pro falls back to USD on an
  older free.

## Commands

- All gates: `scripts/ci-gates.sh` (php -l, every `tests/test-*.php`, release lockstep). The
  pre-push hook runs it (`git config core.hooksPath .githooks`). GitHub Actions is retired on
  purpose, there is no CI.
- One test: `php tests/test-<name>.php`. Tests are plain PHP against `tests/bootstrap.php` stubs.
- Lockstep across all three repos: `scripts/check-lockstep.sh` (versions, changelog entry,
  release signature).
- Build: `./build.sh` makes `dist/nonprofit-manager.zip` from `git archive HEAD`, so only committed
  code ships. Every dev-only file must be listed `export-ignore` in `.gitattributes` (this file is).

## Testing beyond unit tests

Don't call a static read a test. A throwaway WordPress at any version takes minutes: recipe in
memory `reference_headless_wp_test_rig`, plus `tests/rig/` here and in Pro. Three traps:
- mysqld's socket path must be under about 103 characters (`/tmp/x.sock`).
- Never name a top-level variable `$file` in a script that requires `wp-load.php`. Core clobbers it
  and `activate_plugin()` fails with a misleading message.
- Fire admin hooks in a real admin context (`define('WP_ADMIN', true)` plus `set_current_screen()`).

For SQL, run the exact statements against cloudpanel MySQL through `wp db query` with a throwaway
table. Anything with infra behind it: verify against the real endpoint and SHA-256 diff uploads.

## Code landmines

- `$wpdb->prepare()` reads every `%` as a placeholder. A literal percent inside SQL, like
  `DATE_FORMAT('%Y-%m-%d')`, must be `%%` or the arguments shift and the query silently returns
  nothing. `tests/test-prepare-placeholders.php` scans both plugins.
- Recurring frequency is stored as both `'annual'` and `'yearly'`. Anything totalling recurring
  donations must match both (weekly counts 52/12 per month).
- wptexturize mangles `<` inside inline scripts on block themes and turns every later `&&` into
  `&#038;&#038;`. Keep `<` out of the donation forms' inline JS (`tests/test-inline-scripts.php`).
- `get_all_donations()` is unbounded and has no caller, but it's public API, so it stays. The
  Donations screen table is unpaginated by product choice.
- Both Smart Buttons forms build their PayPal order from `npmp_paypal_purchase_unit()`. Pro's
  opt-in setting adds a DONATION item through the `npmp_paypal_donation_item` filter, and its
  checkbox hangs off `npmp_paypal_api_settings_fields` and `npmp_paypal_api_settings_save`.
- PayPal orders on a site with no API secret saved can't be verified, and
  `npmp_paypal_verify_order()` accepts them on purpose (failing closed would drop paid gifts). An
  admin notice asks for the secret.
- The X share uses OAuth 1.0a with HMAC-SHA1 (`includes/social-sharing/networks/x-twitter.php`).
  Never exercised against the live X API.

## Pending changelog

Changes merged to main since the last release, one line each in the house style. Changelog
entries are written at the bump, never as a version block ahead of it. At the next bump, move the
free lines into that version's `readme.txt` entry and the Pro lines into the licence server's
`version.ts` entry, then empty both lists.

Free (`readme.txt`):
- Fixed a PayPal error on some donate pages.
- Removed unused code from the Payment Settings screen.
- Fixed a Stripe gift going unrecorded when the site couldn't save it on the donor's return.

Pro (licence server changelog):
- Added an option to mark PayPal gifts as donations.

## Releasing: three systems, always in this order

Free and Pro always ship the same `YYYY.MM.N` version (memory `feedback_npm_version_policy`). Free
shipping alone breaks lockstep, and Pro shipped inside wp.org's hold once put CACC on mismatched
versions. Commit and tag all three repos first, because the pre-push gate compares all three.

1. **Bump.** Free: `Version:` in `nonprofit-manager.php`, `Stable tag:` and a changelog entry in
   `readme.txt` (one short bullet per change, no why, memory `feedback_wporg_changelog_brief`),
   starting from the Pending changelog lines above, then empty that list.
   Pro: `Version:` and `NPMP_PRO_VERSION` in `nonprofit-manager-pro.php`. Site:
   `CURRENT_VERSION` and a changelog entry with a literal version heading in
   `src/pages/api/license/version.ts`.
2. **Commit and tag** `v<version>` in free and Pro.
3. **Sign.** In Pro, `./release.sh v<version> --dry-run` builds from the tag, signs with
   `~/.npm-secrets/release-signing.key` and prints `CURRENT_ZIP_SHA256` and
   `CURRENT_ZIP_SIGNATURE`. Put both in the site's `version.ts`, commit, tag, push all three. Do
   NOT deploy the site yet: it would advertise a zip R2 doesn't hold, and every Pro install refuses
   the checksum and mails its owner "failed to update" (10.1 and 10.2 shipped that way).
4. **Free to wp.org.** `./build.sh`, unzip `dist/nonprofit-manager.zip` into a temp dir,
   `cd ~/wp-svn/nonprofit-manager-svn && svn up`, rsync the unzipped folder into `trunk/` with
   `--delete`, `svn add`/`svn rm` what `svn status` shows, `svn ci trunk` (screenshots and banners
   go in `assets/`). Tag with full URLs:
   `svn cp https://plugins.svn.wordpress.org/nonprofit-manager/trunk https://plugins.svn.wordpress.org/nonprofit-manager/tags/<version>`
   (`^/trunk` fails). Read tags from the server (`svn ls .../tags/`), never the working copy.
5. **Wait out the six-hour hold.** WordPress.org holds every release about 6 hours before the
   update-check API offers it. The plugin page, info API and zip switch at once and prove nothing.
   Check with a POST to `api.wordpress.org/plugins/update-check/1.1/` with installed `Version` 0.
   A second release during the hold supersedes the first, so ship Pro for whatever update-check
   offers. Pattern that works: a one-shot scheduled task for steps 6 to 8 (memory
   `reference_wporg_release_cooldown`).
6. **Pro to R2.** `./release.sh v<version>` in Pro. It refuses until wp.org offers the free
   version (`NPMP_SKIP_WPORG_CHECK=1` only for an emergency Pro-only fix), uploads with `--remote`
   to `nonprofit-manager-downloads/nonprofit-manager-pro-latest.zip`, and checks a live customer
   download's SHA-256.
7. **Licence server.** Steps in the site repo's CLAUDE.md (build, drop `dist/server/.dev.vars`,
   deploy with the fleet token).
8. **Verify.** `./release.sh --verify` in Pro (uses download slots, 5 an hour per IP),
   `scripts/check-lockstep.sh`, `/pricing` 200, then on CACC `wp plugin list --skip-update-check`.
   A briefly stale version right after deploy is edge propagation, retry once.

Don't run wp-cli on a site while its auto-updater is installing. The updater rewrites the plugin
folder and any WordPress load in that window drops it from `active_plugins`. If you force an update
by hand, run the updater alone, wait, then `wp plugin list --skip-update-check` and reactivate
anything inactive.
