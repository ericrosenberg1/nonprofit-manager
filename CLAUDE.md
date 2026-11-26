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
- You get Local's mysqld on Pacific time (SYSTEM), so you see a TIMESTAMP literal you seed come back 8 hours off. Seed under `SET time_zone = '+00:00'`, then put the session back before you run the code under test.
- You start a new site on plain permalinks. Before you request a pretty URL from a rig, switch the site to post-name links in the rig and put the old structure back at the end (see `tests/rig/event-sources.php`).
- When you narrow the free total with `NPMP_IMPORT_FREE_MAX_ROWS`, you also narrow one job's row limit. Widen `npmp_import_max_rows` in the import phase, or on a site with no contacts you get only the first few rows.
- To see which options you left behind, diff `SELECT option_name, MD5(option_value) FROM wp_options` before and after the run.
- With Pro's `tests/rig/stripe-fake.php` in `mu-plugins/`, we record every message in option `rig_mail` through `pre_wp_mail` and send none, so take the fake out before you run a rig that reads mail (`event-ics.php`, `contact-form.php`, `newsletter-queue-cron.php`).
- You can run the licence server locally for a licence test: `npm run build`, a dummy `dist/server/.dev.vars`, each `migrations/*.sql` through `wrangler d1 execute --local --persist-to <dir>`, then `wrangler dev --local --persist-to <dir>`. Send Pro's `nonprofitmanager.app/api/license/` calls to the local server with a `pre_http_request` mu-plugin, and play several public sites from one rig with a `home_url` filter.

For SQL, run the exact statements against cloudpanel MySQL through `wp db query` with a throwaway
table. Anything with infra behind it: verify against the real endpoint and SHA-256 diff uploads.

## Code landmines

- A host can run PHP without mbstring. WordPress polyfills only `mb_substr()` and `mb_strlen()`
  (6.0 and up) and `mb_chr()` and `mb_ord()` (absent in 6.8.2, present in 7.1), so any other `mb_*`,
  `iconv*`, or `grapheme_*` call needs a guard. Use the helpers in `includes/npmp-mb-compat.php`
  (`npmp_mb_upper()`, `npmp_mb_lower()`, `npmp_encode_numeric_entities()`).
  `tests/test-mbstring-fallbacks.php` runs them with the functions disabled.
- `$wpdb->prepare()` reads every `%` as a placeholder. A literal percent inside SQL, like
  `DATE_FORMAT('%Y-%m-%d')`, must be `%%` or the arguments shift and the query silently returns
  nothing. `tests/test-prepare-placeholders.php` scans both plugins.
- Recurring frequency is stored as both `'annual'` and `'yearly'`. Anything totalling recurring
  donations must match both (weekly counts 52/12 per month).
- wptexturize mangles `<` inside inline scripts on block themes and turns every later `&&` into
  `&#038;&#038;`. Keep `<` out of the donation forms' inline JS (`tests/test-inline-scripts.php`).
- **Plugin Check.** The wp.org team scans every update with Plugin Check, so we run the scan before a release. Build the SQLite rig above,
  install `plugin-check` (2.2.0 or later), unpack `git archive HEAD` into `wp-content/plugins/nonprofit-manager`, and run
  `wp plugin check nonprofit-manager --format=json` (about a minute, with a `FILE:` line and a JSON array per file).
  We ship with 0 ERRORs. We keep these ignores on purpose, each with its reason on the line: `strip_tags()` where
  `wp_strip_all_tags()` would drop script and style text a person typed (UM level name, past-newsletter subject, From name)
  or where no WordPress is loaded (ICS and meeting-ID fallbacks), `fread()` of 3 bytes for the CSV byte-order mark, and
  `OffloadedContent` on Cloudflare Turnstile's siteverify URL, the Cloudflare dashboard link, and the Amazon SES SMTP host.
  We bundle the PayPal button image (`assets/images/paypal-mark.png`) and never hotlink the PayPal CDN. We never use `%i` for table
  names, because we support WordPress 6.0 and `%i` needs 6.2. `readme.txt` keeps the long title on purpose, so
  `mismatched_plugin_name` stays. We keep each Upgrade Notice entry at 300 characters or fewer.
- `get_all_donations()` is unbounded and has no caller, but it's public API, so it stays. The
  Donations screen table is unpaginated by product choice.
- Both Smart Buttons forms build their PayPal order from `npmp_paypal_purchase_unit()`. Pro's
  opt-in setting adds a DONATION item through the `npmp_paypal_donation_item` filter, and its
  checkbox hangs off `npmp_paypal_api_settings_fields` and `npmp_paypal_api_settings_save`.
- PayPal orders on a site with no API secret saved can't be verified, and
  `npmp_paypal_verify_order()` accepts them on purpose (failing closed would drop paid gifts). An
  admin notice asks for the secret.
- **`update_option()` on an option with no row never fires `update_option_<name>`.** WordPress hands it to
  `add_option()` and fires `add_option_<name>` instead. `npmp_enabled_features` has no row on a new install, so
  the first save of the Features screen and the setup wizard both took that path, and the newsletter queue
  drain (`npmp_process_queued_newsletters`), scheduled only from activation and `update_option_npmp_enabled_features`,
  was never scheduled: every newsletter queued, none sent, and the Scheduled Emails screen read "Sent to 2
  members" with an empty mail log (plord12 on wordpress.org, 2026-11-19, ROS-4612). Every per-minute job now
  re-arms itself on `init`, the way the weekly digest and the scheduled-email sweep always did. Hook a cron
  schedule to `init`, never to a settings write alone. Unit `tests/test-scheduled-emails.php`, rig
  `tests/rig/newsletter-queue-cron.php`.
- **The newsletter queue table has a schema version** (option `npmp_newsletter_queue_db_version`, constant
  `NPMP_NEWSLETTER_QUEUE_DB_VERSION`, since the release after 2026.11.26). The old `npmp_newsletter_queue_table_created`
  flag ran dbDelta once, so an existing table never got a new column. We upgrade from the drain (inside its lock) and
  from an admin's screen load, never a visitor's page, since a new index on millions of rows can outlast the request.
  `npmp_upgrade_newsletter_queue_table()` runs dbDelta
  under `npmp_mail_queue_lock( 'newsletter_queue_schema', 600 )`, checks the result with
  `npmp_newsletter_queue_schema_missing()`, and after a refused ALTER waits an hour (transient
  `npmp_newsletter_queue_db_retry_later`). Until the version matches, we drain the 2026.11.26 way. Change the table, the
  constant, and that column list in the same pass. We put a failed send back to pending with `retry_at` (UTC) 5, 15, and
  45 minutes out (`npmp_newsletter_retry_delays`) and mark it failed on the fourth failure. We end a newsletter or digest
  batch after 3 failures in a row or 240 seconds (`npmp_mail_queue_batch_seconds`), and refresh the lock every 30 seconds
  with a compare-and-swap (`npmp_mail_queue_batch_continues()`). On 2026.11.26, with an SMTP sink at 1 second a message and
  330 readers, a run outlived the 300-second lock and a second run mailed 29 readers twice. Unit
  `tests/test-newsletter-queue-retry.php`, rig `tests/rig/newsletter-queue-cron.php` (MySQL and SQLite, `tests/rig/smtp-sink.py`).
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
  writes `gap` itself (`npmp_events_loop_gap_css()`). The excerpt keeps paragraph and line breaks (since
  2026.11.10): `npmp_events_loop_excerpt()` returns plain text with blank lines and
  `npmp_events_loop_excerpt_html()` prints one `<p>` per paragraph. On the theme's own `/events/` and event
  category archives, `npmp_events_archive_excerpt()` (`get_the_excerpt` at 11) hands a classic theme's
  `the_excerpt()` the same text, so it prints paragraphs. Block themes' Post Excerpt block strips breaks itself.
  `past` has three values (`npmp_events_loop_past_mode()`, since 2026.11.15): `"true"` lists only events that
  already started, `"all"` lists past and upcoming, anything else upcoming. Before 2026.11.15 `"true"` meant all
  (Pete, plord12). The Events block's "Include past events" toggle and the calendar's list view setting send `"all"`.
  Pro adds a Groups column on the Events Overview through `npmp_events_overview_columns` and
  `npmp_events_overview_cell`. The events post type sits outside core's menu, so the Events menu links
  Event Categories itself.
  Real-WordPress check: `tests/rig/events-loop.php`.
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
  Every import runner takes `npmp_mail_queue_lock( 'import_<source>' )` first and hands back its state while another
  run holds it (a reload during a 47,501-row MailPoet run made 82 contacts twice). Left-behind counts go through
  `npmp_import_count_emails_not_on_contacts()`, never a `LOWER() = LOWER()` subquery, which timed the hub out at 50,000 rows.
  A gift keeps the contact's status (`NPMP_Member_Manager::status_after_gift()`), only a contact with none becomes Donor.
  When a source's data comes back after step 5 finished, the hub forgets the old check and removal
  (`npmp_import_hub_data_returned()`), so a new delete needs a new check and the typed word.
  A source with step 5 unavailable counts as finished once step 4 is done (`npmp_import_hub_finished()`), and step 4's
  line promises a last step only when the source has a `batch` (`npmp_import_hub_remove_plugin_text()`).
  A source's `import_running` callback puts step 1 at In progress while the importer screen is partway
  through, and every importer screen prints `npmp_import_hub_back_link( $slug )` with the hub's current step.
- **Never look contacts up by email one row at a time in an import.** `npmp_email` meta has no index, so
  50,000 MailPoet rows took 1,415 seconds that way. Call `NPMP_Member_Manager::lookup_emails()` once per
  batch. It fills a request-scoped index that `get_member_by_email()` reads, and `add_member()`,
  `update_member()`, `delete_member()`, and any `npmp_email` meta write keep it right. Rig `tests/rig/import-email-lookups.php`.
  With Pro, MailPoet and Newsletter batches also defer term counting, so a group is counted once per batch.
- **Free imports fill in passes** (`npmp_import_fill_passes()`: subscribed, pending, rest). MailPoet and
  Newsletter save the pass with the cursor and reset the cursor between passes, so a reload resumes in
  the right pass. CSV and API imports keep file or provider order.
- **TEC parents come from the term tables.** `npmp_tec_read_categories()` uses
  `npmp_event_import_post_terms()`, which marks parents `assign => false`, so the migrate step makes
  the whole tree with TEC off and assigns each event only its own categories (rig `tests/rig/tec-categories.php`).
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
- **Past gifts from a file** (`includes/import/gift-csv-parse.php`, pure, and `gift-csv-source.php`). A CSV from Zeffy,
  Givebutter, Donorbox, or a spreadsheet goes through the GiveWP/Charitable gift engine as source `gift_csv`. It opens from
  a card on the Import screen (`npmp_import_source_cards`, printed in `admin-import.php`) and isn't on Switch Plugins, since
  it has no plugin to turn off. Its gift-engine filter goes on only inside its own handlers (`npmp_gift_csv_engine_on()`),
  so the plugin-switch gift screen never lists it. The check pass writes an index file beside the upload in `npmp-private/`,
  one line per donor (`email TAB byte offsets`), and the engine's cursor is a byte position in that file, so 50,000 rows
  cost one short string per donor. Presets map only columns the tool's own help center names: Givebutter's full list
  (help.givebutter.com article 2219206, dates UTC), Zeffy's Amount, Payment method, Payment status, and Refunded amount
  (support.zeffy.com, the rest by generic header match), and no Donorbox preset, since its help center never lists the
  donation export's columns. The engine has no refunded state, so refunded, disputed, failed, and unfinished rows stay out,
  a partial refund comes in as the amount kept, unknown statuses stay out until the owner ticks them, rows with no email
  stay out, and recurring payments come in as past gifts with their frequency and no plan. Identity: the transaction ID
  when mapped, else email, UTC date, amount, and currency. Uploads still expire three hours after the last step. Unit
  `tests/test-gift-csv.php` (fixtures in `tests/fixtures/gift-csv-*.csv`), rig `tests/rig/gift-csv.php` (and `big` for
  50,000 rows), run with Pro on and off.
  Vendor-documented headers (`tests/fixtures/gift-csv-*-docs.csv`, built by `make-gift-csv-docs-fixtures.php`, which cites each
  page) caught three breaks in 2026.11.19: a Givebutter lost dispute (`Dispute Status`) came in as a gift, a Givebutter
  offline gift with a blank `Email` was skipped though `Contact Email` held the donor (now a fallback, with the contact's
  name), and Donorbox's `Donation Type` never reached Frequency. Zeffy and Donorbox publish no export header list for the
  date, name, or email columns, so those fixtures are guesses from their import templates.
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
- **Event Organiser, My Calendar, and WP Event Manager** (`event-organiser-source.php`, `my-calendar-source.php`,
  `wp-event-manager-source.php`, same engine). Engine keys added for them: `brings`, `leaves`, `warning` (lines on the
  import screen), `query_args` (an old link like `/mc-events/x/?mc_id=12` leads to that one date), and a `settings`
  table of checkboxes (`npmp_event_settings[]`, unticked copies nothing). The hub's `npmp_event_hub_source()` takes a
  7th `$extra` array for `delete_note`, and a hub source sets `delete_note_setting` to false when the old plugin has
  no setting to switch off, so step 3 shows the warning without asking for a change (Event Organiser). EO 3.12.12: `event` posts (EM uses that post type too, so `has_data` checks
  `{prefix}eo_events`) plus `eo_events` rows in site time, all-day in `_eventorganiser_event_schedule`, venues are
  `event-venue` terms with `eo_venuemeta`. EO's uninstall always drops its tables and terms and leaves the posts, so
  `delete_note` is always on. My Calendar 3.8.13: tables `my_calendar` and `my_calendar_events`, `event_approved` 3 is
  cancelled (draft), 2 trash (stays), `event_flagged` spam (stays). It deletes only when `drop_tables` is `'true'`.
  WP Event Manager 3.4.2: `event_listing` posts, `_event_start_date` plus `_event_start_time`, a per-event time zone when
  `event_manager_timezone_setting` is `each_event`, `_cancelled` makes a draft, `expired` is a past event. It deletes only
  when `event_manager_delete_data_on_uninstall` is on. `event_manager_per_page` is the one setting that maps (list length).
  None deletes on deactivation, and step 5 is unavailable for all three. Seeding: EO's `eo_insert_event` needs
  `'venue' => array( $id )` (a bare int makes a term named after the number), MC needs a wp-admin visit first. Unit
  `tests/test-more-event-sources.php`, rig `tests/rig/more-event-sources.php` (phase `remove` runs step 4).
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
- **Ultimate Member** (`includes/import/ultimate-member-importer.php`, loaded right after the PMPro importer, whose
  writer `npmp_pmpro_import_member()` it reuses with `source`, `status`, `merge_status`, and `raw` options). Version
  2.14.2 from wordpress.org verified. UM has no tables: members are WordPress users, status is user meta
  `account_status` (approved, awaiting_email_confirmation, awaiting_admin_review, inactive, rejected, or empty for
  users from before UM), and its custom roles live in option `um_roles` plus `um_role_{key}_meta`, added to WordPress
  as `um_{key}` only while UM runs. Read roles from the capabilities meta, never `wp_roles()`, or they vanish with UM
  off. The owner ticks roles on Review (UM roles and registration roles start ticked, staff roles never). Approved
  members come over as Inactive unless the owner says they agreed to newsletters, awaiting statuses as Pending,
  deactivated as Inactive, rejected as Unsubscribed. Never read user meta `submitted`: UM stores the whole signup
  form there. Custom profile fields go out through `do_action( 'npmp_import_profile_fields', ... )` and
  `npmp_import_profile_fields_supported`, and Pro has no receiver yet. Step 5 is unavailable (UM's meta names are
  generic and its add-ons share the `um_` prefix), and `delete_note` warns when `um_options['uninstall_on_delete']`
  is on. UM's hourly job approves every user with no status, so rig `tests/rig/ultimate-member-importer.php` holds
  the `doing_cron` lock. Its registration action needs `um_register_allow_nonce_verification` off and
  `UM()->form()->usermeta_whitelist` filled when no form posts. Unit `tests/test-ultimate-member-importer.php`.
- **Every plugin importer runs inside `npmp_import_run_as_import()`**, so `npmp_is_importing()` is true and Pro's
  "someone signs up" automation and the Mailer's member_joined event skip imported contacts. PMPro, MailPoet, The
  Newsletter Plugin, and past gifts lacked it in 2026.11.10, and Pro queued a welcome for every new contact they made.
  Unit `tests/test-import-as-import.php` scans `includes/import/` and fails on any function that creates a contact
  without the flag (it sets the flag itself, or every caller does). Pro rig `tests/rig/import-no-welcome.php` proves it on
  a real site.
- **Custom fields as an import target** (`includes/import/extra-fields.php`, Pro's `includes/custom-fields/import-target.php`).
  Free offers the columns no contact field takes and Pro writes them through four filters: `npmp_import_extra_fields_supported`,
  `_targets`, `_prepare` (makes the fields), and `_write` (validates and saves, returns written, unchanged, and skipped counts). The
  wizard's mapping values are `cf_new` and `cf:<field id>`. A staged source (MailPoet, FluentCRM) registers on
  `npmp_import_extra_field_sources` and its screen fires `npmp_import_extra_fields_choices`, `_save`, and `_results` with the source
  key. Nothing gets made until the first imported row, and Pro remembers what it made in `npmp_pro_import_made_fields` so a rerun
  reuses the field. A value that doesn't fit is skipped and counted, an empty value never clears a field, and a single checkbox
  stays one yes-or-no box. Without Pro the controls show locked with the `import_custom_fields` link and nothing is written. Unit
  `tests/test-import-extra-fields.php`, rig `tests/rig/extra-fields.php` (CSV plus MailPoet, run with Pro on and off).
- **An existing unsubscribe always wins in the wizard.** `NPMP_Import_Manager::keep_unsubscribe()` drops a row's status on the
  Update path when the contact is unsubscribed here (`tests/test-import-keep-unsubscribe.php`). The staged importers get the same
  rule from `npmp_mailpoet_merge_status()`.
- **FluentCRM** (`includes/import/fluentcrm-importer.php`, hub source `fluent-crm`, 3.3.1 verified). Reads `fc_subscribers`,
  `fc_lists`, `fc_tags`, the pivot `fc_subscriber_pivot` (object_type `FluentCrm\App\Models\Lists` or
  `...\Tag`), and `fc_subscriber_meta`, and custom field definitions from `fc_meta` (`key` is a reserved word, so backtick it). `created_at` is site-local.
  Goes through the MailPoet writer with source `fluent-crm`: pending stays Pending, transactional comes over as Inactive, and
  bounced, complained, and spammed come over as Unsubscribed. Tags join the free Tags field (your letter case wins, a comma in a
  tag becomes a space), lists become Pro groups. Settings: only `fluentcrm-global-settings['email_settings']` from name, from email,
  and reply-to. Double opt-in is per form and list in FluentCRM, so nothing maps. FluentCRM deletes nothing on deactivation or
  deletion, and FluentCRM Pro and its add-ons share the `fc_` tables, so step 5 is unavailable. A WordPress user linked to a
  contact stays untouched. Unit `tests/test-fluentcrm-importer.php`, rig `tests/rig/fluentcrm-importer.php`.
- **Brevo API import** (`includes/import/brevo-api.php`, a source on the Import screen). Never run against the live Brevo API: rig
  `tests/rig/brevo-import.php` drives the real AJAX handlers through a `pre_http_request` fake (pagination, 401, 403, 429, a
  timeout). The pasted key goes only in Brevo's `api-key` header with redirects off. Between requests the site keeps it sealed with
  sodium secretbox in transient `npmp_import_brevo_<token>` (15 minutes, refreshed each step, deleted on finish or stop), and only
  the browser holds the secret (`brevo_secret`), so the database alone never has the key. A preview someone abandons leaves the
  sealed box until the transient expires. Every error message is ours and never quotes Brevo. A 429 (waits `x-sib-ratelimit-reset`
  seconds), a timeout, or a 5xx keeps the job and answers `code: retry`, and the wizard retries five times and then offers Resume.
  The step forces the status column, so a blocklisted address, or one that left the list you import, always comes over
  Unsubscribed. Transactional, calculated, and global attributes stay behind. `createdAt` becomes Added.
- **Editor boxes go in the sidebar** (since 2026.11.8). In the block editor a meta box with context `normal`
  lands in the collapsed "Meta Boxes" panel under the content, which Pete at U3A read as a different screen
  from the newsletter editor. Event Details and Pro's Scheduled Emails use `side`, with `widefat` inputs, and
  Pro stacks its tables there with `td[data-label]`. Every admin h1 needs `hr.wp-header-end` within two
  lines or core puts notices above the title (`tests/test-admin-ui.php` scans for it).
  Changing a box's context isn't enough: `do_meta_boxes()` re-adds each ID in the user option
  `meta-box-order_<screen>` in its saved area, so anyone who reordered a box while it sat in `normal`
  keeps it there (Pete again on 2026.11.12). `includes/npmp-editor-boxes.php` takes our IDs out of every
  area but `side` on `get_user_option_meta-box-order_npmp_event` and `_npmp_newsletter`. Every new box on
  those screens goes on `npmp_sidebar_box_ids()` (Pro adds its own through the filter of that name).
  Unit `tests/test-editor-box-order.php` scans `includes/` for unlisted boxes, rig `tests/rig/editor-box-order.php`
  reads the areas the block editor gets over HTTP (Pro on and off). The event editor's Email Template box
  (`templates.php`) feeds `npmp_scheduled_emails_pick_template()`, which skips a deleted or trashed template.
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
  A newsletter's featured image (since the release after 2026.11.20) goes in lists (`image`, on by default, events-list
  markup) and on the page. Inside the theme we add nothing, since the fake page carries the newsletter's ID and the theme
  prints its own featured image, as it does for an event. The standalone page prints a `<figure>` and Open Graph tags. The
  email never carries it, as event emails carry an image only where the author puts `[event_image]`. Unit
  `tests/test-newsletter-image.php`, rig `tests/rig/newsletter-image.php`.
  `group="..."` needs Pro (`npmp_newsletter_archive_groups_supported`). Without it a list naming a group stays
  empty. Unit `tests/test-newsletter-archive.php`, rig `tests/rig/newsletter-archive.php` (pretty permalinks,
  site served at its home URL, Twenty Twenty-Five and Twenty Twenty-One installed, run with Pro on and off).
- **Past newsletters from MailPoet and The Newsletter Plugin** (`includes/import/past-newsletters.php`, pure parts in
  `past-newsletters-pure.php`, imported-newsletter guards in `includes/email-newsletter/imported.php`). A box on each importer's
  Approve step (hooks `npmp_import_approve_parts` and `npmp_import_after_migrate`) runs after contacts. Only MailPoet `standard`
  + `sent` + not deleted, and TNP `message` + `sent`, come over, as `npmp_newsletter` posts with status `sent`, `_npmp_import_key`
  `<source>:<id>` (a rerun skips them), no stats, and `_npmp_newsletter_public` unset. MailPoet's stored rendered HTML
  (`sending_queues.newsletter_rendered_body`, cleared after 30 days) wins over the JSON blocks, and the block converter reports which
  newsletters lost formatting. Never run MailPoet or TNP code to render. `npmp_past_nl_sanitize()` strips scripts, forms, pixels, the
  plugins' tracking (restoring the real link from MailPoet's `newsletter_links` or TNP's `nltr` and `/tnp/l/`), and unsubscribe, manage,
  and view-in-browser links, and turns personal tags into `[first_name default="..."]`. An imported newsletter can never send:
  `queue_newsletter()` returns 0, the drain empties its body, and the editor, Send now, and Send later all refuse
  (`npmp_newsletter_is_imported()`). The runner sends the source in the ajax action name (`npmp_past_newsletters_<slug>`), since
  `npmp-tec-import.js` posts only action, nonce, and restart. Pro's `includes/groups/past-newsletters.php` matches list names to existing
  groups and never creates one. Unit `tests/test-past-newsletters.php` and `tests/test-newsletter-public-bulk.php`, rig
  `tests/rig/past-newsletters.php` (real MailPoet 5.45.2 and Newsletter 9.5.7 together, `NPMP_RIG_SINK` set to `tests/rig/smtp-sink.py`'s
  output, Pro on and off). The stub-based `tests/rig/mailpoet-importer.php` overwrites a real MailPoet, so it refuses to run beside one.
- **Donation forms inside an email and prefill** (`includes/payments/npmp-donation-links.php`). `npmp_email_the_content()`
  sets `npmp_email_is_rendering()` for its render, and `npmp_render_donation_form()` then prints a table-based Donate button
  to the donation page (option `npmp_donation_page_id`, else the first published page holding the form, else nothing). The
  link ends in `npmp_donor=[npmp_donor_key]`, added after `esc_url()` because WordPress 7.1's `esc_url()` percent-encodes the
  brackets. A newsletter fills the tag through `npmp_email_recipient_tags` with a signed token (HMAC scoped `donor-prefill|`,
  90 days), and a `wp_mail` filter strips an unfilled tag from plugin mail. Forms prefill only from the logged-in user or a token
  that verifies, never from a bare address, and set `DONOTCACHEPAGE` when a token fills one in. The Payment link gateway
  (`payment_link`, free, option `npmp_payment_links`, up to 5) saves only https templates with no placeholder in the host, and
  swaps the placeholders out around `esc_url_raw()`, which strips braces. Unit `tests/test-donation-links.php`, rig
  `tests/rig/donation-links.php` (set `NPMP_RIG_URL` for the HTTP prefill checks, run with Pro on and off).
- **Meeting ID, passcode, and "Show the meeting link only in emails"** (`includes/npmp-event-meeting.php`, loaded on
  every request). Meta `_npmp_event_meeting_id`, `_npmp_event_passcode`, `_npmp_event_link_email_only` sit beside the
  unchanged `_npmp_event_url`. With the box ticked, we leave all three off the event page, and `npmp_get_event_details()`
  returns them blank unless the caller passes `true`. Until the release after 2026.11.19 we printed the link on no other
  public surface (lists, blocks, calendar views, iCal, REST, oEmbed, feeds, search). The iCal feed now carries it for an
  event that shows it, through `npmp_get_event_details()` without the flag. Print it on any new surface only through
  `npmp_event_meeting_public()`, and never through `npmp_event_meeting_details()`, which adds Pro's group defaults. We fill emails from `npmp_event_meeting_details()` (filter `npmp_event_meeting_details`, where
  Pro adds group defaults), and we take out the whole line of a `[meeting_id]` or `[passcode]` with no value
  (`npmp_scheduled_emails_collapse_empty()`). We mark an event email from a private event with
  `_npmp_newsletter_meeting_private` and never give it a public newsletter page. We added the two lines to the built-in
  Tomorrow body once, only where nobody changed it (option `npmp_scheduled_email_bodies_meeting`). Unit
  `tests/test-event-meeting.php`, rig `tests/rig/meeting-links.php` (run its `before` phase on the released free, then
  the branch, and we compare an old event's page and email byte for byte). After you swap the plugin symlink under
  `php -S`, restart the server, or its workers keep serving the old real path.
- **iCal text and the .ics on event emails** (`includes/npmp-ics.php`, pure, and `includes/npmp-event-ics.php`, both loaded
  on every request). We build each VEVENT for the feed and the email file with `npmp_ics_vevent()`: TEXT escaping, a URI value
  for http and https links alone, and folding at 75 octets without splitting a UTF-8 character (a first for
  the feed). We put the join link in LOCATION after the place, in URL, and in DESCRIPTION with the meeting ID and passcode. For
  the feed we pass only public details, so we keep an email-only event's details and Pro's group defaults out of the feed. We attach `<event-slug>.ics`
  (`METHOD:PUBLISH`, the feed's UID, every detail) to event emails (`_npmp_newsletter_scheduled_event`) and to free's and Pro's
  "Send a test to me" (`npmp_scheduled_emails_render_test()` sets `npmp_event_ics_test_event()`), through the
  `npmp_newsletter_mail_attachments` filter in `NPMP_Newsletter_Manager::send_email()`. We attach it only where
  `npmp_mail_attachments_supported()` answers yes: WordPress's mailer, SMTP, and SES. Pro's SendGrid, Brevo, Mailgun, and
  Postmark senders hand any message with an attachment to WordPress's mailer (`npmp_pro_handle_email()`), and that would move
  the email off the service the site picked, so on those four we send no file. Teach the four senders attachments before you
  add them to the supported list. We write the file to a random folder under `get_temp_dir()` and delete it at shutdown. We give an
  event with no end, or an end not after its start, one hour. A 00:00 start with no end or a 23:59 end is all day
  (`npmp_ics_all_day_span()`, the same rule as the emails' "All day"), and we write it as DATE values with DTEND the day after the
  last day (RFC 5545 3.6.1). Unit
  `tests/test-ics.php`, rig `tests/rig/event-ics.php` (SMTP sink, the feed over HTTP, SendGrid faked, and Pro on and off).
- **Embeds in a newsletter's email** (`includes/email-newsletter/email-embeds.php`). Mail apps show no iframe, so in the email
  we swap each `core/embed` block (a `render_block` filter while `npmp_email_is_rendering()`) and each leftover iframe
  (`npmp_email_embed_swap_iframes()`) for a link: "Watch the video", "View the slides", or "View the embedded content". For a
  YouTube video we add `i.ytimg.com/vi/<id>/hqdefault.jpg` as a linked image. We link the web version when the owner ticked
  the newsletter public (`npmp_newsletter_web_version_url()`), else the source. We render the web version and the email with
  the same `render_base_content()`, so we wrap `npmp_newsletter_archive_body()` in `npmp_newsletter_web_render()` and skip the
  swap inside that wrapper. Unit `tests/test-email-embeds.php`, rig `tests/rig/email-embeds.php` (oEmbed faked, SMTP sink, the page over HTTP).
- **Other plugins' blocks in a newsletter's email** (`includes/email-newsletter/email-plugin-blocks.php`, since 2026.11.23, Pete's "more-plugins" topic). In an email render only, we swap an embedded ONLYOFFICE block, and every
  bare `[onlyoffice]` shortcode, for ONLYOFFICE's own link view through its registered shortcode handler, or print nothing
  when ONLYOFFICE is gone. ONLYOFFICE 2.3.0 reads `documentView` in camelCase and WordPress lowercases shortcode names, so
  a bare shortcode always embeds. We cut a TablePress table (block and `[table]`) to its first page of rows
  (`datatables_paginate_entries` from the block's `parameters` or the shortcode, else the table's saved options, else
  TablePress's default of 10, no cut when `use_datatables` or `datatables_paginate` is off) and add "Showing the first X of
  Y rows." with a web-version link. We turn off TablePress's Edit link in the email with `tablepress_edit_link_below_table`.
  We checked against TablePress 3.4.1. Unit `tests/test-email-plugin-blocks.php`, rig `tests/rig/email-plugin-blocks.php`.
- **Footer `[preferences_url]`** (alias `[subscribe_url]`, Pete's name for it, same release). We fill both with
  `npmp_can_spam_get_preferences_url()`: the preferences page with the reader's signed link, where Pro lists the groups to
  tick. With no preferences page we drop any `<a>` whose href is the tag, and we never fall back to the unsubscribe page.
- **We found no way in for us in Event Bridge for ActivityPub** (we read 2.0.0 on 2026-11-16). Its authors list the plugins
  they support in the `private const EVENT_PLUGIN_INTEGRATIONS` in `Event_Bridge_For_ActivityPub\Setup`, with no filter, so
  we can't add an `Integrations\Event_Plugin_Integration` subclass from outside. We could hand ActivityPub's own
  `activitypub_transformer` filter an `npmp_event` transformer built on their internal `Transformer\Event\Event`, but their
  scheduler, reminders, settings, and health check read only that list. Ask upstream for a filter on the list before you
  build anything.
- **Form pages are picked by name and form, never by text** (`includes/npmp-form-pages.php`, since the release after
  2026.11.20). Setup, the preferences lookup, and the email Donate button used to take the first page whose text held
  `[npmp_email_unsubscribe` and friends, so Pete's notes page "Thoughts on u3acommunities hosting" became his unsubscribe
  page. A page counts when `npmp_form_page_is_clear()` says yes: published, its slug or title holds one of the kind's words,
  and it holds the real shortcode (not `[[escaped]]`) or the block. The Donate button still falls back to the oldest page
  holding the real form when no page is named for giving. A one-time repair (`npmp_maybe_repair_form_pages()`, option
  `npmp_form_pages_repaired`) moves a saved unsubscribe or preferences page only when it isn't clear, it mentions the form
  (the old search's only way in), and a clear page exists. Otherwise we create and change nothing, and setup status warns.
  The preferences lookup remembers a miss for an hour (`npmp_prefs_page_lookup`). We clear it with
  `npmp_forget_preferences_page_miss()` on every page save, trash, restore, and delete and on Membership Settings save
  (`tests/test-preferences-page-miss.php`), so a page made after a miss shows up at once. Any new cached miss needs the same clearing.
  We send newsletters as HTML only, with no plain-text part, and the footer URL's `&#038;` decodes to `&` (checked with
  macOS textutil and PHP's `html_entity_decode()`). Pete's `&#038;` likely came from elsewhere, unconfirmed. Unit `tests/test-form-pages.php`, rig `tests/rig/form-pages.php`.
- **Event tags become hashtags** (taxonomy `npmp_event_tag`, flat, no public archive, since the release after 2026.11.20).
  `NPMP_Social_Share_Manager::get_hashtags()` reads the tags (map on `npmp_social_hashtag_taxonomies`, filter
  `npmp_social_hashtags`), and `format_for_network()` hands every network, free and Pro, the text from
  `npmp_social_share_text()` (`includes/social-sharing/hashtags.php`, pure). Hashtags go where the template says
  `{hashtags}`, else on their own line at the end. Within a network's limit the title and link stay, then as many hashtags
  as fit, then the excerpt is cut. Only networks named on `npmp_social_char_limits` get fitted, so a network whose own file
  cuts text needs its limit there (Pro adds Threads). Reddit posts the title only, so it gets none. The visitor share bar's X
  button adds `&hashtags=` on an event's own page. Bluesky needs a tag facet per hashtag (Pro's `npmp_pro_bsky_tag_facets()`).
  Unit `tests/test-social-hashtags.php`, rig `tests/rig/social-hashtags.php` (every network faked, run with Pro on and off).
- **Help links** (`includes/npmp-help-links.php`, since 2026.11.22). One map, `npmp_help_links()`, sends each admin
  screen (a `page` value, `post_type:<type>`, or `taxonomy:<taxonomy>`) to an article at nonprofitmanager.app/help/<slug>/.
  Screens whose title goes through `npmp_admin_page_title()` get a "Help" link beside the title, and every mapped screen gets
  a Nonprofit Manager tab in WordPress's Help panel. Pro's screens sit in the same map. The articles live in the site repo
  (`wordpress/help/articles/`), so rename an article there and change its slug here in the same pass.
  `tests/test-help-links.php` fails on a slug with no article when the site repo is beside this one.
- **PayPal gifts are one-time** (since 2026.11.22). The free PayPal buttons and Pro's PayPal API gateway take no
  frequency: `npmp_get_enabled_frequencies()` maps only Stripe's options, `npmp_ajax_log_donation()` records
  `one_time`, and Payment Settings has no PayPal frequency boxes. Recurring gifts are Stripe only
  (`tests/test-paypal-one-time.php`).
- **We never rate-limit the PayPal logger** (`npmp_ajax_log_donation()`). We post each captured PayPal order from the
  browser once and ignore the answer, so if we refused a post we'd lose a paid gift, and with a per-site cap one abuser could
  block every gift. We refuse only a claim with no order ID. We cap the contact form (`contact_form`, 10 an hour per visitor,
  `contact_form_site`, 100) and Pro's join checkout (`join_checkout`, 10 in 10 minutes per visitor, no site cap) with
  `npmp_rate_limit_allows()`, and you can change or turn off each cap with the `npmp_rate_limit_max` filter. Unit `tests/test-donation-replay.php` (sections 5 and 6),
  `tests/test-contact-form-limits.php`, Pro's `tests/test-join-checkout.php`, rigs `tests/rig/contact-form.php` and Pro's
  `tests/rig/join-limits.php`.
- **We cache the Dashboard's two donation queries for 10 minutes** (`npmp_dashboard_donations_cache_get()` in
  `includes/npmp-dashboard-widgets.php`). We store the rows with the token from option `npmp_dashboard_donations_token`, and
  we change the token at shutdown after any save, status change, or delete of a donation, or any write to its amount,
  currency, frequency, or email meta. If you write donations with raw SQL, you skip those hooks, so call
  `npmp_dashboard_donations_changed()` after the write. Unit `tests/test-dashboard-donation-cache.php`, rig `tests/rig/dashboard-donation-cache.php`.
- **One donation row per gateway transaction** (since the release after 2026.11.25). `log_donation()` reads "is this
  transaction on file?" and writes under `npmp_mail_queue_lock( 'donation_txn_<md5>' )`, and `find_by_transaction_id()`
  asks with `cache_results` off. WordPress keeps a query's result for the rest of a request, so before that a second
  look never saw a row another request wrote in between: six Stripe returns in the same millisecond wrote up to six
  rows, and one PayPal order posted twice 300 ms apart wrote two, each with its own thank-you. A request that waited
  for another one's row gets `false`, so each caller checks `find_by_transaction_id()` before it reports a failure
  and never thanks the donor again. When the lock never frees and no row shows up, we write without the lock, since
  the gateway already has the money. Unit `tests/test-donation-txn-lock.php`, rig `tests/rig/donation-race.php`
  (real processes, run on MySQL and SQLite with Pro on and off).
- **A one-time Stripe gift comes in three ways** (since the release after 2026.11.26): the donor's return
  (`npmp_maybe_finalize_stripe_donation()`), free's webhook (`admin-ajax.php?action=npmp_stripe_webhook`,
  `includes/payments/npmp-stripe-webhook.php`), and Pro's older address. All three go through
  `npmp_stripe_record_checkout_session()`, and we record only a paid payment-mode session with `metadata.gateway=stripe`.
  We read `log_donation()`'s `$created` to learn whether that call wrote the row, and we thank the donor only then, so a
  caller that got the lock after another request wrote the row sends nothing (the PayPal logger checks it too). We load
  the webhook on every request, outside the Donations module, and with Pro we hand every verified event to
  `npmp_stripe_process_event()`. Pro claims each event id once, so we process an event once even when a site has both
  addresses in Stripe. Each Stripe endpoint has its own signing secret, so the Signing Secret box takes several
  (`npmp_stripe_webhook_secrets()`: spaces, commas, or new lines, each starting `whsec_`, and two pasted run together split
  at `whsec_`), and both addresses pass a signature made with any of them. With no secret saved, we answer 503 at the
  webhook, record nothing there, and still record the gift on the donor's return. The dedupe looks at every post status,
  so a gift the owner trashed stays trashed when Stripe sends it again. Unit `tests/test-stripe-webhook.php`, rig
  `tests/rig/stripe-webhook.php` (MySQL and SQLite, Pro on and off, `NPMP_RIG_URL` for the HTTP checks).
- **Two sites on one Stripe account each get the other's events** (fixed the release after 2026.11.26). Stripe sends
  every event on an account to every endpoint, so a second Nonprofit Manager site recorded the first site's gifts,
  added its donors, and thanked them in its own name, and with Pro made members and dues rows from its joins. Every
  session and subscription free and Pro create carries `metadata[npmp_site]`, `npmp_stripe_site_tag()`: 16 hex of
  sha256 of the saved Site Address (option `home`) with the scheme, a leading `www.`, and a trailing slash dropped.
  Never change the formula, or open sessions and live subscriptions stop matching. `npmp_stripe_session_is_this_site()`
  requires a matching tag, and a session from before the tag counts when its `success_url` comes back here. The
  recorder answers `'foreign'` (200, so Stripe stops), the donor's return skips it, Pro's dues handler skips it, and
  Pro's invoice backfill (`npmp_stripe_subscription_is_ours()`) refuses another site's tag. A subscription from before
  the tag carries none, so two sites can still both adopt it there. Rig `tests/rig/stripe-webhook.php` with
  `NPMP_RIG_OTHER_WP` and `NPMP_RIG_OTHER_URL` sends each event to two served sites.
- **WordPress's personal data tools** (`includes/npmp-privacy.php`, loaded on every request, since the release after
  2026.11.28). We add exporters `nonprofit-manager-contact` and `nonprofit-manager-gifts` and eraser `nonprofit-manager`
  to Tools, Export and Erase Personal Data, plus suggested privacy policy text and a card on General Settings. We match
  email with `LOWER()` across every post status. An erase deletes the contact, its queue and digest rows, and its opens
  and clicks, keeps each gift with date, amount, currency, and frequency but no name or email (`_npmp_privacy_erased`),
  and NULLs `npmp_payment_log.raw_response`. A published unsubscribed contact stays as email, status, and
  `_npmp_free_import` only (filter `npmp_privacy_keep_unsubscribed`, default true). Gifts go 100 a page
  (`NPMP_PRIVACY_PAGE_SIZE`), so core calls the eraser until `done`. Queue and tracking deletes run on page 1 only
  (a full scan with no email index, about 24 ms at 100,000 queue rows on MySQL 8). We never call Stripe or PayPal,
  cancel anything, or touch WordPress users. Free owns every hold and reads Pro's tables itself, so holds work with Pro
  on, off, old, or never installed: a plan row in `npmp_privacy_billing_statuses()` (active, failed, lapsed, not
  one-time) holds with one message per status. Paused doesn't hold ("Stop after this period" bills nothing until
  Resume). Lapsed can mean Stripe still retries or Stripe already deleted the sub, so it holds, and Pro's Cancel works
  on a lapsed row. With no Pro eraser on core's list (Pro off or older than the release after 2026.11.28) and any Pro
  data left (`pro_leftovers()`: custom fields, groups, automation log or cron, Mailer rows other than a pending delete,
  plans with a payment method), we hold and tell the admin to activate or update Pro. A gift title is stored empty
  after an erase: read names through `npmp_donation_donor_name( $title, $email, $id )`, which prints "Erased on
  request". The stub keeps `post_modified` (we store no unsubscribe date) and `_npmp_privacy_erased`. Notes export
  through filter `npmp_privacy_export_notes` (default true). The old `npmp_members`, `npmp_contacts`, and
  `npmp_donations` rows export as "Older records". The gift CSV import key (`_npmp_import_key`, `csv:r…`) is an unsalted
  hash of email, date, amount, and currency, and an erase keeps the key. Any new table or meta that names a person
  needs a line in the exporter and the eraser. Unit `tests/test-privacy.php` (fake store), rig `tests/rig/privacy.php`
  (`wp eval-file`, core's AJAX handlers, Pro on and off, plus older Pro, newer Pro beside an older free, and no Pro
  tables).
- The X share uses OAuth 1.0a with HMAC-SHA1 (`includes/social-sharing/networks/x-twitter.php`).
  Never exercised against the live X API.

## Pending changelog

Changes merged to main since the last release, one line each in the house style. Changelog
entries are written at the bump, never as a version block ahead of it. At the next bump, move the
free lines into that version's `readme.txt` entry and the Pro lines into the licence server's
`version.ts` entry, then empty both lists.

Free (`readme.txt`):
- (none)

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
