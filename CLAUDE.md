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

## Brand tokens

One look on every Nonprofit Manager surface (site, plugin admin, front-end forms, tour, mailer pages,
emails). The source of truth is the site's Perdita tokens,
`nonprofit-manager-site/wordpress/child-theme/perdita.json`: primary #10B981, accent #34D399,
secondary #0f766e, link hover #059669, heading #111827, text #1f2937, Playfair Display headings,
Inter body, radius 8. White text on #10B981 fails WCAG AA (2.5:1), so filled buttons, links and focus
rings in the plugin use #0f766e (5.5:1), hover #115e59, tint #ecfdf5, tint border #a7f3d0, and
#10B981 stays decorative (notice borders, glows). Leave WordPress's own `.button-primary` on the
admin color scheme. Front-end forms read `--npmp-accent`, `--npmp-accent-dark` and
`--npmp-accent-text` so a theme can override them. The site owner's Form color (option
`npmp_forms_accent`, `includes/npmp-forms-accent.php`) prints those three only when it differs from
#0f766e, derives the hover shade, and switches the label to dark text when white fails 4.5:1. The calendar's unsaved highlight falls back to #0f766e, and
`npmp_maybe_pin_legacy_calendar_accent()` saves the old blue once on sites that upgraded from
before 2026.10.11 so their public calendars don't change. Change a color here and on the site,
Pro and `~/Code/nonprofit-mailer/edge/src/index.js` in the same pass.

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
memory `reference_headless_wp_test_rig`, plus `tests/rig/` here and in Pro. No MySQL on this Mac?
`tests/rig/stripe-return.php` also runs on SQLite: untar a cached core from `~/.wp-cli/cache/core/`,
add the `sqlite-database-integration` plugin with its `db.copy` as `wp-content/db.php` (fill its two `{SQLITE_...}` placeholders), `wp core
install`, symlink this repo into `wp-content/plugins/`, then `NPMP_RIG_WP=<wp dir> php tests/rig/...`
(verified 2026-10-16, 20 of 20). Three traps:
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
- **The From rewrite is scoped, the SMTP connection is not.** `npmp_email_from_applies()` decides:
  mail sent through `npmp_send_mail()` gets the configured From, any other `wp_mail()` only when
  `from_all_mail` is on ("Send all site email from this address", default off). Custom SMTP and
  Amazon SES carry every message on the site either way. Before 2026.10.8 the transport called
  `setFrom()` on all site mail. Send plugin mail through `npmp_send_mail()`, never bare `wp_mail()`
  (`tests/test-mail-from-scope.php`, real-WordPress rig `tests/rig/mail-from-scope.php` with
  `tests/rig/smtp-sink.py`). `force_from` and `set_return_path` have no checkbox, so every settings
  save writes 0 to both.
- Amazon SES (provider `aws_ses`) sends over SMTP with the access key ID as login and the SMTP
  password derived from the IAM secret (`npmp_email_ses_smtp_password()`, AWS's SigV4 recipe),
  ports 587, 2587, 465, 2465. Before 2026.10.8 it fell back to PHP `mail()` without a word.
  DigitalOcean blocks 587 and 465, so SES users there need 2587.
- **Words on screen follow the kind of group** (option `npmp_org_type`, `includes/npmp-org-type.php`,
  since 2026.10.9). Ask for "Donors", "Donations", "Membership Levels" or the menu name with
  `npmp_label( 'donors' )` and friends, wrapped in `sprintf( __( 'Recent %s' ) )` with a translators
  comment. Never call it at file load (translations load at init). Pro goes through
  `npmp_pro_label( $key, $fallback )` so it still runs on an older free. Internal names (tables, options,
  post types, caps, hooks, REST routes, menu slugs) never change. `tests/test-org-type.php` fails on
  any `npmp_label()` key missing from the map, rig `tests/rig/org-type.php` drives the wizard and
  settings over HTTP.
- **WordPress names submenu screens after the parent's menu title.** Renaming "Nonprofit Manager" to
  "Club Manager" would turn `nonprofit-manager_page_npmp_general_settings` into
  `club-manager_page_...` and break every screen check. `npmp_pin_menu_hook()` right after each
  `add_menu_page()` keeps the old IDs. Any new top-level menu with a changing title needs it too.
- **Event lists render in one place.** `npmp_events_loop_render()` in `includes/npmp-events-loop.php`
  serves `[npmp_events]`, the Events block and the calendar's list view. Layout, order, image and
  excerpt come from option `npmp_events_loop`, then shortcode attributes or block attributes. The block
  skips its wrapper attributes inside a REST request, because the editor already puts the block
  supports on its own wrapper. Core writes block gap only for blocks with a layout, so the loop
  writes `gap` itself (`npmp_events_loop_gap_css()`). Real-WordPress check: `tests/rig/events-loop.php`.
- **Email bodies render through `npmp_email_the_content()`, never bare `apply_filters( 'the_content' )`**
  (since 2026.11.5). It takes browser-only callbacks from other plugins off the filter for the
  render and puts them back (list in `npmp_email_browser_only_content_filters()`, today Creative
  Commons' attribution box). `npmp_email_prepare_images()` then swaps local WebP, AVIF and HEIC
  files for PNG or JPG copies in `uploads/npmp-email-images/`. SVG is never rendered on the server
  (most hosts can't, and rendering an uploaded SVG can read server files), so the test send warns
  the author instead. Unit test `tests/test-email-image-formats.php`, rig
  `tests/rig/email-image-formats.php` with the Creative Commons plugin active.
- **The free import limit is a total for the site** (`includes/import/import-cap.php`, since the
  release after 2026.11.5): 100 new contacts, counted as the `npmp_contact` posts that carry
  `_npmp_free_import`. A listener on `npmp_import_row_imported` writes that mark when the status is
  `imported`, so an importer that creates contacts itself must ask `npmp_import_free_remaining()`
  before each new one and fire that action after. Updates never count, a deleted contact gives its
  place back, and contacts imported before this carry no mark. `npmp_import_max_rows()` is still the
  row limit for one job, and Pro's filter on it turns both limits off. Unit
  `tests/test-import-cap.php`, rig `tests/rig/import-cap-total.php` (Pro inactive).
- **Switch Plugins hub** (`includes/import/import-hub.php`). Every importer that retires an old plugin
  registers a source on the `npmp_import_sources` filter (keys documented at the top of the file, TEC's in
  `includes/import/tec-hub-source.php`). Five steps in order, every refusal in `npmp_import_hub_run_step()`.
  Register only sources with a working importer. Data removal deletes only what the source names and shows
  as unavailable without a `batch` callable. TEC's removal refuses while Event Tickets is installed
  (tribe-common shares its option names) and never touches `npmp_*` options, the npmp_event posts, or their
  `_npmp_tec_*` meta, which the 301s read. Unit `tests/test-import-hub.php`, rig `tests/rig/import-hub.php`
  (needs the real TEC, deletes it, runs each step in its own `wp` process). MailPoet is the second source
  (`includes/import/mailpoet-hub-source.php`): removal drops every `{prefix}mailpoet_*` table, its options and
  `mailpoet_page`/`mailpoet_email` posts, and leaves Action Scheduler's rows. Verify counts MailPoet subscribers
  with no matching contact, since the free limit (`npmp_import_max_rows()`) leaves people only in MailPoet.
  Rig `tests/rig/mailpoet-hub.php` runs on real MailPoet (5.45.2 activates and takes API calls on SQLite).
  When a source's data comes back after step 5 finished, the hub forgets the old check and removal
  (`npmp_import_hub_data_returned()`), so a new delete needs a new check and the typed word.
- **Past gifts from GiveWP and Charitable** (`includes/import/past-gifts.php`, `gift-importer.php`,
  `givewp-source.php`, `charitable-source.php`). A past gift is an `npmp_donation` post written directly,
  never through `log_donation()`, so Pro's automations mail no receipt and the first-donation milestone
  stays unset (the Powered-by backfill in `npmp-credit-ask.php` skips posts with `_npmp_import_key`).
  `_npmp_import_key` = `<source>:<id>` makes a rerun skip what's here. Donors follow the free total, and a
  donor past it keeps every gift in the old plugin. Both sources read the old tables straight from the
  database, so the import runs with the plugin off, and read only the `currency` key of `give_settings`
  and `charitable_settings` (both hold Stripe keys), plus the one delete setting each (`uninstall_on_delete`,
  `delete_data_on_uninstall`). GiveWP money received is `publish` and
  `give_subscription`, meta in `give_donationmeta` or older tables by `give_completed_upgrades`, and its
  `createdAt` is site-local. Charitable's amount is the sum of `charitable_campaign_donations` rows, money
  received is `charitable-completed` only, and a Charitable Recurring renewal (paid add-on, no source)
  is stored with frequency `recurring`. Step 5 is unavailable for both: GiveWP's own uninstall and
  Charitable's Reset Data on deactivation delete their data. So GiveWP's source sets `delete_note` (step 4)
  while Remove Data on Uninstall is on, and Charitable's sets `deactivate_note` (step 3) while Reset Data is
  on, since Charitable deletes on deactivation even though its label says uninstall. Each note names the
  setting so the owner can switch it off first. The other five sources delete nothing on deactivation or
  deletion (checked in EM 7.4.14, Sugar Lite 3.17.0, Newsletter 9.5.7, MailPoet 5.45.2, TEC 6.18.3.1). Unit `tests/test-past-gifts.php`,
  `tests/test-gift-sources.php`, rig `tests/rig/gift-sources.php` (real GiveWP 4.19.1.1 and Charitable
  1.8.15.1, Pro inactive).
- **Events Manager and Sugar Calendar** (`includes/import/event-importer.php`, `events-manager-source.php`,
  `sugar-calendar-source.php`). One engine for event plugins other than TEC: sources register on
  `npmp_event_import_sources` and hand back one item per date, and the engine writes npmp_event posts with
  `_npmp_import_key` = `<source>:<id>` and `_npmp_import_old_path`, then 301s those paths on a 404. Both sources read
  the old tables straight from the database, so you can import with the plugin off. EM 7 saves a repeating
  series as `em_events` rows of type `recurrence` with no post of their own, so each date borrows the parent's
  post through `em_event_recurrences`, and only the first date carries the series link. A cancelled EM event
  comes over as a draft. Sugar Lite keeps only the meta keys it registers (`location`, no `url`), and its
  repeats come over as the first date. Categories bring their parents (`npmp_event_import_post_terms()`).
  Step 5 is unavailable for both. The hub's turn-off step drops `rewrite_rules` so a dead plugin's `/events/`
  routes stop answering. Neither plugin has a setting that maps to ours. Unit `tests/test-event-sources.php`,
  rig `tests/rig/event-sources.php` (EM 7.4.14, Sugar Calendar Lite 3.17.0, visit wp-admin once so both make
  their tables).
- **The Newsletter Plugin** (`includes/import/newsletter-importer.php`, loaded with the members module
  right after the MailPoet importer's files). Subscribers come from `{prefix}newsletter` (status letters C, S, U, B, P, and T for
  a signup that never finished, which stays behind) and go through the MailPoet writer,
  `npmp_mailpoet_import_subscriber( $row, $known, $lists, 'newsletter' )`, so status merging, signup dates,
  groups (Pro), and the free total match MailPoet. Settings come only from `newsletter_main` (sender name,
  sender email, reply-to), `newsletter_subscription` (`noconfirmation`, 0 for double opt-in), and
  `newsletter_lists`, through `npmp_import_apply_settings_plan()`. Newsletter ships no uninstall code, and its
  add-ons share the `newsletter_` names, so step 5 is unavailable. Unit `tests/test-newsletter-importer.php`,
  rig `tests/rig/newsletter-importer.php` (Newsletter 9.5.7, visit wp-admin once so Newsletter creates its tables).
- **Paid Memberships Pro** (`includes/import/pmpro-importer.php`, loaded right after the Newsletter importer). PMPro left
  wordpress.org in 2024, so get its source from GitHub (strangerstudios/paid-memberships-pro, tag `3.9.1` verified). Members
  are WordPress users with rows in `{prefix}pmpro_memberships_users`. A current member has a row with status `active` (PMPro's
  own test), anyone else is a past member. Current members come over as Active with their newest level, past members as
  Inactive with no level. Every PMPro level name joins `npmp_membership_levels`. A level set by hand on an existing contact
  stays. `startdate` holds site-local time (checkout writes `current_time( 'mysql' )`), `user_registered` holds UTC. The
  importer reads only the levels and memberships tables, the pmpro_b* address meta, and `pmpro_uninstall`, never orders,
  subscriptions, or gateway settings. Members with a renewing plan keep paying through the processor after PMPro is off, so
  Verify and the import screen warn. Step 5 is unavailable. PMPro's own uninstall drops its tables when "Uninstall PMPro on
  deletion?" is Yes, and the hub's optional `delete_note` then warns in step 4. PMPro's reads use MySQL's `CONVERT_TZ()`, which
  SQLite can't parse, so rig `tests/rig/pmpro-importer.php` seeds with PMPro's writers and reads its tables directly. Unit
  `tests/test-pmpro-importer.php`.
- **Editor boxes go in the sidebar** (since 2026.11.8). In the block editor a meta box with context `normal`
  lands in the collapsed "Meta Boxes" panel under the content, which Pete at U3A read as a different screen
  from the newsletter editor. Event Details and Pro's Scheduled Emails use `side`, with `widefat` inputs, and
  Pro stacks its tables there with `td[data-label]`. Every admin h1 needs `hr.wp-header-end` within two
  lines or core puts notices above the title (`tests/test-admin-ui.php` scans for it).
- **Every marketing email to a contact ends with `npmp_email_recipient_footer( $email )`** (newsletter
  footer with that reader's unsubscribe link, plus the preferences link) and carries
  `npmp_get_list_unsubscribe_headers()`. Notifications and the digest use it, and Pro's automation engine
  uses it and skips a contact whose status is `unsubscribed` at send time, except on a transactional
  trigger (only a failed payment, see Pro's CLAUDE.md), which gets the address and preferences link instead. Confirmation, unsubscribe,
  receipt, and admin mail stay without it. Unit `tests/test-signup-welcome-emails.php`, rig
  `tests/rig/signup-welcome-emails.php`, Pro rig `tests/rig/automation-footer.php`.
- **Public newsletters are opt-in, one newsletter at a time** (`includes/email-newsletter/archive.php`,
  pure parts in `archive-pure.php`). A newsletter is listed and gets a page at `/newsletter/<id>/<slug>/`
  only when `_npmp_newsletter_status` is `sent` and someone with the send capability ticked the sidebar
  box (meta `_npmp_newsletter_public` = `1`). Every newsletter starts unticked, older ones included. Event
  emails (`_npmp_newsletter_scheduled_event`) stay out of lists unless `event_emails="yes"`. The page renders
  `NPMP_Newsletter_Manager::render_base_content()` (made public for this) with tag defaults, never
  `personalize_content()`, and `npmp_newsletter_archive_scrub()` strips tokens, addresses, and tracking from
  any pasted link. It is noindex (wp_robots and an X-Robots-Tag header) unless Newsletter Settings says
  otherwise, and anything else answers 404. The page shows inside the theme: the main query becomes one fake
  `page` that carries the newsletter's ID and a marker as content, and `npmp_newsletter_archive_filter_content()`
  (the_content at PHP_INT_MAX) swaps in the finished content, built before the query changes so the body passes
  the_content once. That drops comments, share buttons, related posts, and the plugin's own page injections.
  When the theme's template never prints the content, `archive-theme-template.php` sends the standalone page
  (header `X-NPMP-Newsletter-View: standalone`), and the `npmp_newsletter_archive_use_theme` filter forces it.
  `group="..."` needs Pro (`npmp_newsletter_archive_groups_supported`). Without it a list naming a group stays
  empty. Unit `tests/test-newsletter-archive.php`, rig `tests/rig/newsletter-archive.php` (pretty permalinks,
  site served at its home URL, Twenty Twenty-Five and Twenty Twenty-One installed, run with Pro on and off).
- The X share uses OAuth 1.0a with HMAC-SHA1 (`includes/social-sharing/networks/x-twitter.php`).
  Never exercised against the live X API.

## Pending changelog

Changes merged to main since the last release, one line each in the house style. Changelog
entries are written at the bump, never as a version block ahead of it. At the next bump, move the
free lines into that version's `readme.txt` entry and the Pro lines into the licence server's
`version.ts` entry, then empty both lists.

Free (`readme.txt`):
- Fixed event lists and the Events block dropping the paragraph breaks in an event's text.

Pro (licence server changelog):
- (none)

## Releasing: three systems, always in this order

Free and Pro always ship the same `YYYY.MM.N` version (memory `feedback_npm_version_policy`), and
since 2026-11-12 they go live at the same moment. There is no wait for wordpress.org's six-hour
update hold. What keeps a site from getting Pro before free is the licence server, not a delay: Pro's
updater reports the free version installed and the one wordpress.org offers, and the server hands
back the newest Pro no newer than those that runs beside the installed free (site repo
`src/lib/pro-releases.ts`). Pro N then arrives in the same update run as free N. Every Pro version
stays in R2 as `nonprofit-manager-pro-<version>.zip` and the download link names it, so an upload
never changes bytes the server already advertises. Commit and tag all three repos first, because the
pre-push gate compares all three.

1. **Bump.** Free: `Version:` in `nonprofit-manager.php`, `Stable tag:` and a changelog entry in
   `readme.txt` (one short bullet per change, no why, memory `feedback_wporg_changelog_brief`),
   starting from the Pending changelog lines above, then empty that list.
   Pro: `Version:` and `NPMP_PRO_VERSION` in `nonprofit-manager-pro.php`. Site: in
   `src/lib/pro-releases.ts` move the outgoing `CURRENT_` values to the top of
   `PREVIOUS_PRO_RELEASES`, set `CURRENT_VERSION` and `CURRENT_MIN_FREE_VERSION` (Pro's
   `NPMP_PRO_MIN_FREE_VERSION`), and add a changelog entry with a literal version heading in
   `src/pages/api/license/version.ts`.
2. **Commit and tag** `v<version>` in free and Pro.
3. **Sign.** In Pro, `./release.sh v<version> --dry-run` builds from the tag, signs with
   `~/.npm-secrets/release-signing.key` and prints `CURRENT_ZIP_SHA256` and
   `CURRENT_ZIP_SIGNATURE`. Put both in the site's `src/lib/pro-releases.ts`, commit, tag, push all
   three. Don't deploy the site by hand: `pro-leg.sh` deploys it after the upload, so the server never
   lists a version R2 doesn't hold.
4. **Free to wp.org.** `./build.sh`, unzip `dist/nonprofit-manager.zip` into a temp dir,
   `cd ~/wp-svn/nonprofit-manager-svn && svn up`, rsync the unzipped folder into `trunk/` with
   `--delete`, `svn add`/`svn rm` what `svn status` shows, `svn ci trunk` (screenshots and banners
   go in `assets/`). Tag with full URLs:
   `svn cp https://plugins.svn.wordpress.org/nonprofit-manager/trunk https://plugins.svn.wordpress.org/nonprofit-manager/tags/<version>`
   (`^/trunk` fails). Read tags from the server (`svn ls .../tags/`), never the working copy.
5. **Pro and the licence server, right away.** In Pro,
   `nohup scripts/pro-leg.sh <version> <sha256> </dev/null >/dev/null 2>&1 &`. It runs
   `./release.sh v<version>` (refuses until free `<version>` is in SVN, uploads the versioned zip,
   reads it back), deploys the licence server from the site's `v<version>` tag, waits for the version
   API to answer `<version>`, refreshes the legacy `-latest.zip`, and runs `./release.sh --verify`.
   Log and status in `~/Library/Logs/npm-release/pro-leg-<version>.{log,status}`. It takes minutes.
   Never under launchd with KeepAlive: on 11-01 that restarted it every 10 seconds after a
   rate-limited download (496 runs, 496 R2 uploads) and used up the comp key's hourly quota, which
   then 429'd CACC's Pro update.
6. **Verify.** `./release.sh --verify` in Pro (uses download slots: 40 an hour per licence key and 120
   per IP, and CACC shares the comp key, so a loop here also blocks CACC's Pro update),
   `scripts/check-lockstep.sh`, `/pricing` 200, a version request with
   `{"free_version":"<previous>"}` answering the previous Pro and one with `"free_offered":"<version>"`
   answering the new one, then on CACC `wp plugin list --skip-update-check`. A briefly stale version
   right after deploy is edge propagation, retry once. Sites see free and Pro together once
   wordpress.org's hold ends (about six hours after the SVN tag), and anyone who updates free by hand
   before then gets the matching Pro at once.

Don't run wp-cli on a site while its auto-updater is installing. The updater rewrites the plugin
folder and any WordPress load in that window drops it from `active_plugins`. If you force an update
by hand, run the updater alone, wait, then `wp plugin list --skip-update-check` and reactivate
anything inactive.

## Writing voice

Every word of prose in this repo follows Eric's voice card at `~/Code/content-toolkit/voice/voice-card.md`. That covers UI copy, emails, docs, release notes, store listings, commit messages, and PR bodies. Point at the card and never paste its rules here, since a copy drifts. Gate short copy with `python3 ~/Code/content-toolkit/scorer/ai_tells.py FILE --profile article|email|social|title|description --brief` (zero blocks, 80 or higher) and long-form with `content-voice FILE` (90 or higher, 85 is the floor).
