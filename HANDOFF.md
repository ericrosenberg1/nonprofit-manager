# Nonprofit Manager: Development Handoff

Written 2026-07-13. Read this instead of replaying the conversation history that produced it.
For fix-level detail, `git log` in each repo has full commit messages.

## What this product is

Nonprofit Manager is a WordPress plugin: free tier on wp.org, paid Pro tier sold via a
license server. Built and dogfooded by Eric at his own nonprofits. Business model is
free-to-acquire, Pro for advanced features (recurring donations, automation, segmentation,
imports).

## Repos and where things live

- **Free plugin:** `~/Code/nonprofit-manager`, GitHub `ericrosenberg1/nonprofit-manager`,
  listed on wp.org. Source of truth for both the plugin code and the wp.org readme/listing.
- **Pro plugin:** `~/Code/nonprofit-manager-pro`, GitHub `ericrosenberg1/nonprofit-manager-pro`,
  proprietary license, distributed only via the license server's download endpoint.
- **License server:** `~/Code/nonprofit-manager/nonprofit-manager-site`, its own separate git
  repo (GitHub `ericrosenberg1/nonprofit-manager-site`), Astro app on Cloudflare Workers plus
  D1, R2, and KV, live at `https://nonprofitmanager.app` (old subdomain nonprofitmanager.ericrosenberg.com 301s, /api/* answers on both hosts for installed Pro clients). Handles license
  activation/deactivation, Stripe checkout/webhooks, the Pro update-check endpoint
  (`/api/license/version`), and Pro zip downloads (`/api/download/[key]`).
- **wp.org SVN working copy:** `/Users/eric/wp-svn/nonprofit-manager-svn`, has cached wp.org
  credentials, so `svn commit`/`svn cp` work non-interactively. Run `svn up` before touching it,
  it goes stale between sessions.
- **Live WP test site:** `ssh cloudpanel`, wp-cli as `sudo -u nonprofitmgr wp ... --path=/home/nonprofitmgr/htdocs/nonprofitmanager.ericrosenberg.com`.
  Free plugin is installed there (currently inactive). Pro was synced there for testing via
  direct rsync, not committed as a permanent deploy. Useful for testing against a real MySQL and
  WordPress runtime without going through a full release.
- **Cloudflare tokens:** a Workers-R2-Storage-scoped token and a D1-Edit-scoped token are stored
  in `nonprofit-manager-site/.env` (chmod 600, gitignored). The main fleet power token in
  `~/.claude/.secrets/fleet.env` covers Workers Scripts:Edit (needed for `wrangler deploy`) but
  not R2 or D1. Mint scoped tokens via the pre-filled-deep-link pattern (see global CLAUDE.md)
  if those run out or get revoked.

## Release pipeline (proven working 2026-07-13)

Both plugins use `./build.sh` (`git archive HEAD`, so **only committed code** gets packaged,
dev-only paths stripped via `.gitattributes` `export-ignore`). Uncommitted WIP needs a direct
rsync to test live, not `build.sh`.

**Free to wp.org:**
1. Bump `Version:` in `nonprofit-manager.php` plus `Stable tag:` and changelog in `readme.txt`.
2. Commit, tag `vYYYY.MM.N`, push to GitHub.
3. `./build.sh` produces `dist/nonprofit-manager.zip`.
4. `svn up` the SVN working copy, unzip the build into a temp dir, `rsync -a --delete` into
   `trunk/`, `svn status` to sanity-check the diff matches what you expect, `svn commit trunk`.
5. `svn cp .../trunk .../tags/YYYY.MM.N -m "Tag YYYY.MM.N"`.
6. Verify via `curl -s https://api.wordpress.org/plugins/info/1.0/nonprofit-manager.json`.
   Takes a few minutes to reflect the new version after the SVN tag, that's normal.

**Pro to license server:**
1. Bump `Version:` and `NPMP_PRO_VERSION` in `nonprofit-manager-pro.php`.
2. Commit, tag, push to GitHub.
3. `./build.sh` produces `dist/nonprofit-manager-pro.zip`.
4. Upload to R2 as `nonprofit-manager-pro-latest.zip` in the `nonprofit-manager-downloads`
   bucket (`wrangler r2 object put`, R2-scoped token). **Verify the upload's SHA-256 matches the
   local build before doing anything else.** This has caught real problems.
5. **Only after the R2 upload is confirmed live**, bump `CURRENT_VERSION` in
   `src/pages/api/license/version.ts`, `npm run build`, check `dist/server/.dev.vars` for a
   leaked token copy (Astro's build process copies `.env` there, delete it, it's never actually
   deployed but shouldn't sit around in plaintext), then `wrangler deploy` with the fleet power
   token. This ordering is a hard rule: the update-check endpoint must never advertise a version
   that isn't actually downloadable yet.
6. Verify live: POST to `/api/license/version` with a real license key, confirm `version` and
   `download_url` are correct, then actually download and SHA-256-diff against the local build.
   If a fresh deploy briefly shows the old version, that's Cloudflare edge propagation delay
   (under a minute), not a bug. Retry once before worrying about it.

## Feature summary

**Free (wp.org):**
Membership/contact management, donations (Stripe one-time, PayPal, Venmo), email newsletters
(Gutenberg editor, templates, HTML and plaintext, open/click tracking, one-click
List-Unsubscribe), weekly digest of new posts/events to subscribers, event calendar
(month/week/list views, Gutenberg blocks, iCal feed), CSV/XLSX/Google Sheets/Mailchimp/Constant
Contact import, social share and contact form blocks, setup wizard, review-nudge, and optional
(off-by-default) "Powered by" attribution.

**Pro (paid):**
Membership dues auto-billing (per-level pricing, public join form, Stripe subscriptions,
failed-payment auto-downgrade), recurring Stripe donations, custom member fields, advanced
segmentation (AND/OR condition builder), email automation engine (5 trigger types: member
created, donation received, membership expired, recurring payment failed, tag added), chunked
Mailchimp/Constant Contact imports, more email providers (Brevo, SendGrid, Mailgun, Postmark,
SparkPost, AWS SES) via the multi-provider email settings, guided provider setup wizard, license
system with activation/deactivation/auto-updates.

## Current state (2026-09-04: Free and Pro are both live at `2026.09.13`)

`2026.09.13` is a Pro-only fix, with Free a no-op lockstep bump (SVN trunk r3681813, tag
r3681814). It closes the gap `2026.09.5` left open: `create_dues_subscription()` cancels the
member's previous recurring level at Stripe before writing the new row and ignored the result,
so a Stripe outage during a level switch left the old subscription billing, wrote the new row
anyway, and answered 200. `npmp_recurring_cancel_at_stripe()` now flags its `WP_Error` as
transient for a network error, 5xx or 429 (`npmp_stripe_error_is_transient()`), and the
level-switch loop fails the delivery on a transient cancel so Stripe retries the whole event:
the retry cancels at Stripe, then writes the new row. A definitive 4xx (already cancelled, not
found, key rejected) falls through as before, so a subscription Stripe already closed cannot
loop for three days. Pro `tests/test-webhook-retry.php` is at 140 assertions, and Pro
`tests/rig/` now holds the throwaway-WordPress scripts (Stripe fake behind `pre_http_request`,
signed POST driver, install and setup) with a README of the exact steps, kept out of the
customer zip by the tests export-ignore.

## Previous state (2026-09-04: Free and Pro were live at `2026.09.12`)

**`2026.09.12` is a bug-and-performance release.** Three fixes, all verified against a
real WordPress 7.1 + MySQL on the `cloudpanel` test site rather than reasoned about:

- **Post notifications emailed the whole subscriber list in one cron run.** A few thousand
  subscribers pushed that run past `max_execution_time`, and since nothing recorded
  progress, everyone after the cutoff was silently never emailed. The weekly digest had
  already been fixed this way; post notifications now page by ascending contact ID and
  reschedule for the remainder. **Two traps here, both caught by testing and both worth
  remembering.** `get_posts()` sets `suppress_filters`, so a `posts_where` filter carrying
  a cursor never runs at all and every batch silently re-reads the first rows. And the
  cursor must travel as a *query var*, not captured in the closure: WordPress keys its
  query cache on query vars, a `posts_where` filter does not change that key, so a
  captured cursor asks for different rows under the previous run's cache key and is handed
  the previous run's rows back. With a persistent object cache that loops forever.
- **Dashboard totals aggregated in PHP.** Member counts ran one unbounded query per
  membership level and `count()`ed the IDs; both donation totals loaded every row and
  summed in a loop. All three are single SQL aggregates now. On 2,500 seeded rows:
  membership summary 6 queries to 1 and 4.3x faster, YTD 3 to 1 and 2.2x, annual recurring
  3 to 1 and 1.5x. The gap widens with row count since the old path was linear in PHP
  memory. Results proven identical on data covering both once-a-year spellings, unknown
  frequencies, missing meta, drafts, and case-varied tier names.
- **Activating the same site twice concurrently returned a 500.** Both requests passed the
  "already activated" check and the second hit `UNIQUE(license_id, site_url)`. The code
  assumed that insert would no-op; SQLite raises, and nothing caught it. Now answered as
  the 409 the caller wanted. The account page also reads licences and orders in parallel
  and fetches activations in one query instead of one per licence.

**The free plugin now has tests** (`tests/`, 18 assertions, excluded from the wp.org zip
via `.gitattributes`) wired into its gates. Pro's gates run its suites too. Anything
needing a real `$wpdb` is verified on the test site instead of against a faked database,
because reproducing MySQL's collation and date handling faithfully enough to trust is more
work than running the real thing.

**Test-site gotcha:** `wp eval-file` runs the file inside a function scope, so top-level
`$vars` are **not** global. `global $x` inside a helper in that file silently gets null.
This produced a wrong benchmark and a cleanup that deleted nothing before it was noticed.
Use `define()` or `$GLOBALS[...]`, and always re-check that seed data was actually removed.

## Previous state (2026-09-04: Free and Pro were live at `2026.09.11`)

**Release lockstep is now enforced, not just intended.** `scripts/check-lockstep.sh` in the
free repo is the gate, wired into the pre-push hook of all three repos. It verifies the free
header against the readme stable tag, free against Pro, the licensing server's advertised
`CURRENT_VERSION` against the shipping Pro version, that the changelog has an entry for that
version, that changelog headings are literal strings rather than `${CURRENT_VERSION}`, and
that Pro's `NPMP_PRO_MIN_FREE_VERSION` floor is satisfied by the shipping free version. Run it
by hand any time: `scripts/check-lockstep.sh`.

It exists because all three had already drifted. The bump to `2026.09.10` did not add a
changelog entry, and because the heading interpolated `CURRENT_VERSION`, it silently retitled
`2026.09.9`'s notes as `2026.09.10` and erased `2026.09.9` from the changelog customers read
in their update screen. **The site repo had no pre-push hook and no gates at all**, which is
how it shipped. It has both now (lockstep plus an astro build), and Pro's gates now run its
test suites, which they also did not before.

**Verified in lockstep at `2026.09.11`** by rebuilding each channel and diffing against its
git tag: the wp.org zip matches free `v2026.09.11` (66 files, identical), the R2 zip matches
Pro `v2026.09.11` (38 files, identical), and the licensing server advertises `2026.09.11`.

**`2026.09.11` shipped two fixes.** Pro's `guard_package_download()`, the last check before
WordPress unzips an update into `wp-content`, only engaged when the package URL contained the
string `nonprofit-manager-pro`. Real download URLs are `/api/download/<key>` and contain no
such string, so it returned early on every genuine upgrade and never checked a host. It now
identifies the upgrade from `$hook_extra['plugin']`. No install was exposed, since the primary
`is_allowed_package_url()` check in `check_for_update()` runs first and always worked.
`tests/test-update-guard.php` drives the real class and fails 5 assertions against the old
predicate. Free gained the **External Services** readme section wp.org requires: the
`2026.09.10` wizard opt-in posts the site owner's email and name to Listmonk and nothing
disclosed it. Consent was already correct (unticked by default).

**Deploy gotcha:** `wrangler deploy` prints `No targets deployed` for this worker because the
built config carries no routes (the custom domain is attached outside wrangler). The version
*is* deployed at 100% regardless. Confirm with `wrangler deployments list --name
nonprofit-manager-site`, and give the edge ~30s before testing the live endpoint or you will
read the previous version and think the deploy failed.

## Previous state (2026-09-04: Free and Pro were live at `2026.09.9`)

**Auto-update is verified working end to end.** Proven on a throwaway WP 7.1 rig with a real
licence key: Pro 2026.09.6 to 2026.09.9 through `Plugin_Upgrader`, downloaded, unpacked,
installed, and the plugin stayed active. Two things had been breaking it:

1. **The download endpoint rate-limited 5/hour/IP.** WordPress runs updates from the web
   server's IP, not a browser, so shared hosting (hundreds of sites behind one IP) and an
   agency updating client sites hit it and got a 429, which the WP upgrader reports as
   "Download failed" and leaves the site on the old version. Now 120/hour/IP as a pure
   bandwidth backstop, with the per-key limit (40/hour) doing the anti-redistribution work,
   since a key is the licensed unit.
2. **No update was shown at all when the licence key was not activated.** The API sends the
   version to everyone but the download URL only to an active licence, and the updater
   returned silently. Fixed in 2026.09.7.

**Testing gotcha:** call `Plugin_Upgrader::upgrade()` outside cron and WP deactivates the
plugin (`deactivate_plugin_before_upgrade` skips this only when `wp_doing_cron()`), and
nothing reactivates it outside the browser flow. Define `DOING_CRON` in the rig or you will
think auto-update deactivates plugins. It does not.

**Lockstep (2026.09.9).** Free owns the shared helpers in `includes/npmp-version.php`
(`npmp_version_status()`, `npmp_versions_in_lockstep()`, `npmp_version_mismatch_message()`),
raises a non-dismissible notice naming which half is behind, and registers a Site Health test.
Pro declares `NPMP_PRO_MIN_FREE_VERSION` (currently `2026.09.0`): below that floor it loads
its licence system and notices and nothing else, so it cannot call free-plugin code that is
not there. Drift inside the floor warns but keeps working. Raise the floor only when Pro
starts depending on something new in free, and say so in the release notes.
`tests/test-lockstep.php` covers it in 20 assertions.

**Account portal** now has the free plugin download (points at wordpress.org), payment history
from `orders`, and a licence key reset (`POST /api/license/reset`, drops every activation and
clears the old key from KV so it dies immediately).

## Previous state

Two releases today. `2026.09.7`: the Plugins screen now explains why an update is not being
offered when the licence key is not active (it used to show nothing at all, which is what a
paying customer hit), and refunds wind a licence down over 30 days instead of disabling it on
the spot. `2026.09.8`: a packaging fix.

**Refund policy:** `handleChargeRefunded` calls `expireLicenseIn(db, id, 30)`. The key stays
`active` and `expires_at` moves 30 days out, so a refunded customer's site keeps working and
Pro updates stop at the end of that window. It never extends an earlier expiry and never
revives an already-expired key.

**Packaging trap:** `.githooks/` and `scripts/` (the pre-push gate) had no `export-ignore`, so
`git archive` shipped them inside both plugin zips. Fixed in both `.gitattributes`. Two things
to remember: `git archive` reads `.gitattributes` from the **committed** tree, so the fix does
nothing until it is committed; and the release checklist's `svn status | grep '^[?!]'` guard is
what caught it, so keep that guard in the flow.

wp.org skipped `2026.09.7` (its free build was aborted mid-release on the packaging catch) and
went `2026.09.6` to `2026.09.8`.

## Previous state

`2026.09.6` is a coding-standards pass, finishing the quality review the accessibility
release started. **phpcs is now installed globally** (`~/.composer/vendor/bin/phpcs`, with the
WordPress, WordPress-Extra and PHPCompatibilityWP standards) so this is repeatable:

```
phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.1- --ignore='vendor/*,dist/*' includes/ nonprofit-manager-pro.php tests/
phpcs --standard=WordPress --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput,WordPress.DB.PreparedSQL,WordPress.WP.I18n --ignore='vendor/*,dist/*' includes/
```

PHP 8.1 through 8.5 is clean. Fixed: dues pricing saved one option write per membership level
(now one write for the whole form, all-or-nothing, and a rejected level is reported instead of
skipped), eight placeholder strings had no `translators:` comment, `sanitize_key()` on `$_POST`
without `wp_unslash()`, and a segment count now casts with `absint()`.

**The ~14 remaining phpcs errors are verified false positives, do not "fix" them:** every one is
`{$this->table}` / `{$table}` / `{$this->table_name()}` interpolation, which is `$wpdb->prefix`
plus a constant, with all user values passed through `prepare()`. One more is a hardcoded
`<span class="required">*</span>` literal concatenated after `esc_html()`. Audited line by line.

Pro's test suite is 204 assertions across `tests/test-one-time-dues.php`,
`test-recurring-frequency.php` and `test-webhook-retry.php`, sharing `tests/bootstrap.php`
(fake `$wpdb` over SQLite, WP stubs, HTTP mocking, failure injection) and
`tests/fakes-free-plugin.php`. GitHub Actions is disabled repo-wide, so run them by hand:
`for t in tests/test-*.php; do php "$t"; done`.

## Previous state

`2026.09.5` is a Pro-only fix, with Free a no-op lockstep bump (SVN trunk r3680395, tag r3680396).
The Stripe webhook claimed each event id up front, ran the handler, and answered 200 whatever
happened in between, so a handler that failed for a temporary reason (Stripe unreachable or
answering 5xx during the ownership check, a database write refused part-way) told Stripe the
event was done and Stripe never retried it. Now `npmp_stripe_subscription_is_ours()` is
tri-state (null means Stripe could not be asked), handlers return `true` or a `WP_Error`, and
the dispatcher `npmp_stripe_process_event()` deletes the claim row and answers 500 on a
`WP_Error` so Stripe's retry can re-claim the event. Same shape as the sales-site Worker's
`stripe-webhook.ts`. Definitive outcomes (not ours, nothing to record, unknown type) still
answer 200. Handlers are safe to re-run from the top: idempotent writes first, the donation
log entry last and never fatal, and `checkout.session.completed` stops at an existing row for
the same Stripe reference so a retry cannot cancel the subscription that was just paid for.
Tests: Pro `tests/test-webhook-retry.php` (110 assertions) plus signed deliveries over HTTP
on a throwaway WP 7.1 rig with a Stripe fake behind `pre_http_request`: a 503 and a network
error each answered 500 with the claim row gone, the retry answered 200 and wrote the row,
the duplicate answered "already processed", a 404 answered 200.

Known gap at the time, closed in `2026.09.13`: `create_dues_subscription()` ignored a failed Stripe cancel of
the member's previous level during a level switch, so a Stripe outage at that moment can leave
two subscriptions billing. Fixing it needs a 4xx/5xx split in
`npmp_recurring_cancel_at_stripe()` so an already-cancelled subscription does not loop.

## Previous state (2026-09-03: Free and Pro were live at `2026.09.4`)

`2026.09.4` is an accessibility and UX pass on the customer-facing screens, audited against
WCAG 2.2 AA on a throwaway WordPress 7.1 install with axe-core. Both admin screens and the
public join form now report zero axe violations. The headline fix: the bundled form
stylesheet never loaded on a page whose only form was Pro's `[npmp_join_form]`, because
`npmp_should_enqueue_form_styles()` listed the free plugin's own four shortcodes and not
that one. The join form and the post-payment confirmation banner both rendered unstyled.
That list is now filterable (`npmp_form_style_shortcodes`). Also: `autocomplete` on name
and email (SC 1.3.5), heading level fixed, focus moves to errors, a real "Taking you to
Stripe" busy state, translated price suffixes, Stripe error text no longer shown to the
public visitor, "Cancelled" badge contrast raised from 4.39:1 to 6.87:1, one shared
"Lapsed" colour, `aria-current` on filter tabs, and the license notice no longer prints the
product name twice.

To re-run that audit: build a throwaway WP per `[[reference_headless_wp_test_rig]]`, symlink
both plugins in, seed levels/pricing/members, then inject axe-core from cdnjs and run it on
`#wpbody-content` (admin) and `document` (front end). Ignore `aria-allowed-role` and `region`
hits on `#wp-admin-bar-*` and `#wp-skip-link`, those are WordPress core's own, logged-in only.

## Previous state (2026-09-03: Free and Pro were live at `2026.09.3`)

Free `2026.09.3` is on wp.org (SVN trunk r3679556, tag r3679557), a lockstep bump that also
carried the unreleased members-screen SQL aggregation from main. Pro `2026.09.3` is in R2 with
the license server advertising it and a byte-verified customer download. All three repos are
tagged `v2026.09.3`. Pro `2026.09.3` hardens the one-time (lifetime) membership dues option
after an adversarial review of `2026.09.1`: `NPMP_Recurring_Manager::is_one_time()` replaces the
scattered `'one_time'` literal, `pause_subscription()` refuses one-time rows server-side (both
admin screens hide Pause for them), a level switch no longer relabels a completed lifetime
payment "cancelled", and the join form stamps `npmp_frequency` + `npmp_amount` into the Checkout
Session metadata so the webhook records what Stripe charged instead of re-reading the level's
pricing (a price edit while a session was open could lose a captured one-time charge, since
`checkout.session.completed` is the only webhook a one-time join ever fires). Pro now has two
plain-PHP tests sharing `tests/bootstrap.php`; run them by hand with
`for t in tests/test-*.php; do php "$t"; done` (GitHub Actions is off).

Previous: Free `2026.09.2` is on wp.org (SVN trunk r3678894, tag r3678895). Pro `2026.09.2` is in R2 with
the license server advertising it and a byte-verified customer download. All three repos are
tagged `v2026.09.2`. That release carried the Pro MRR frequency-vocabulary fix, the free
"One-time" display fix, and Pro `2026.09.1`'s one-time membership dues feature, and it put the
two plugins back in lockstep after Pro shipped `2026.09.1` alone.

Two things worth knowing for the next release. First, **read the live versions before picking a
number** (`curl https://api.wordpress.org/plugins/info/1.0/nonprofit-manager.json` and
`POST /api/license/version`), because a concurrent session can move one plugin without the other,
which is exactly what happened here. Second, **GitHub Actions is disabled at the repo level on
both plugin repos**, so CI is not a gate. Run `php tests/test-recurring-frequency.php` in the Pro
repo by hand.

## Prior state (stale as of 2026-07-24 below)

Free was at **2026.07.5** as of this doc's writing (SVN trunk r3621632 + tag r3621635, GitHub
tag v2026.07.5); both Free and Pro have since moved to **2026.08.4**. 2026.07.5 cleared the top
three known gaps below:

1. **Newsletter SEND queue** migrated off the `npmp_nl_queue` wp_posts CPT onto the
   dedicated `wp_npmp_newsletter_queue` table (gap #1, same pattern as the 2026.07.4
   open/click fix). Added a `maybe_create` guard (the table was missing on installs that
   activated before it existed, e.g. the cloudpanel test site) + a bounded-batch cursor
   migration of any existing queue posts. Also fixed a latent bug where a newsletter
   whose final recipient failed stayed stuck in "queued". Verified: real cloudpanel MySQL
   + 39 logic assertions against the real code.
2. **Pro large-import timeout** (gap #3): CSV/XLSX/Google Sheet/Constant Contact imports
   now drain through the chunked `npmp_import_step` runner instead of one single-shot
   write loop, so a Pro import (row cap lifted to PHP_INT_MAX) can't hit
   `max_execution_time` or a proxy timeout mid-write. `parse_csv`/`parse_xlsx` gained an
   offset window; new `import_file_page()` / `import_constant_contact_page()` page
   methods. Verified: 36 logic assertions (full coverage, no dupes/skips across CSV+XLSX).
3. **Social Sharing 403** (gap #2): root cause was a menu load-order bug — `admin-social.php`
   registered its `npmp_main` submenu at the default `admin_menu` priority 10, same as the
   main scaffold, but loads first, so `add_submenu_page` ran before the parent existed and
   the page hookname mismatched between registration and access. Fixed by registering at
   priority 11 (the pattern the import module already uses). Proven against real WP 7.0.2
   core `get_plugin_page_hookname` (4/4 assertions).

## Prior state (2026.07.4, shipped 2026-07-13)

Both plugins were at **2026.07.4**, confirmed live everywhere: wp.org API, license server
`/api/license/version`, and a byte-identical Pro download. All 6 items from the original
security/perf/UX review that prompted that round are fixed (5 in Free, 1 in Pro):

1. Newsletter open/click tracking now writes to dedicated tables instead of a `wp_posts` row
   per event (Free).
2. CSV/XLSX import parsing no longer materializes the whole file in memory before the row cap
   applies (Free).
3. Weekly digest now enqueues and sends in throttled batches via a self-unscheduling per-minute
   cron instead of mailing the whole list inline in one cron run (Free).
4. Email automation's `sent_count`/`failed_count` no longer race under concurrent WP-Cron
   requests. Stats are now derived from the existing automation log table instead of a
   separately-maintained counter (Pro).
5. `force_from` no longer rewrites the From address on mail from other plugins or core
   WordPress, only mail this plugin itself sends (Free).
6. PayPal donations now persist a full server-side verification record per capture, verified or
   not, instead of discarding the gateway response after checking it once (Free).

That backlog is clear. Nothing is currently known-broken.

## Known gaps / next priorities

0. **Stripe webhook cross-product gap. RELEASED 2026-09-02 in Pro `2026.09.1`, nothing left
   to do.** Both plugins are now on `2026.09.2` (see the release note at the end of this list).
   Background: HSA Tracker, FreelancerDashboard, and Nonprofit Manager share one Stripe account
   with one webhook per app and no product filter. `payment_succeeded`'s auto-backfill
   (`npmp_stripe_create_local_subscription_from_invoice`) created a new NPM member from **any**
   subscription's first successful invoice, HSA/FD subscribers included. The fix (commit
   `38145df`) stamps `subscription_data[metadata][npmp_source]=dues` at Checkout Session
   creation in `public-join-form.php` and checks that metadata via the Stripe API before the
   backfill creates anything. Verified 2026-09-02 against the live customer download: the R2
   zip's sha256 matches the `v2026.09.2` build and both files carry the `npmp_source` check.
   Full incident context: `~/Code/hsatracker/docs/HANDOFF.md`.

Gaps 1-3 below were the newsletter SEND queue, the Social Sharing 403, and the Pro
import write-side timeout. **All three shipped in 2026.07.5 (2026-07-24)** — see the
Current state section above. What remains:

4. **Major planned direction, not started: unified "Supporter" data model.** Collapse member
   and donor into one record, the stated differentiator vs. other WP nonprofit plugins. Ships in
   Free (it's foundational), Pro layers Stripe/recurring/segmentation/automation/QB-export/PDF
   summaries on top. Design doc:
   `/Users/eric/.gstack/projects/ericrosenberg1-nonprofit-manager-site/eric-main-design-20260502-075243.md`
   (status: approved, rev 3). This is a **v3.0.0 MAJOR**, not a quick fix — treat it as its own
   focused effort with checkpoints, not a one-shot autonomous build. Scope from the doc:
   - **Phase 1 (safe, additive, no behavior change):** create the 5 new tables (`supporters`,
     `supporter_giving`, `supporter_membership`, `supporter_attendance`, `payments_webhooks`)
     with `maybe_create` guards; backup hook (dump member+donation tables to
     `npmp_v2_backup_{ts}`, 90-day keep); "v3 Migration Preview" dry-run admin screen (dedup
     report, no writes); commit migration (email-normalized auto-merge, name+ZIP → manual
     review, anonymous → preserve) guarded by `npmp_schema_version`; 90-day rollback action.
     Additive: populates new tables from `wp_npmp_contacts`/`wp_npmp_donations` without removing
     the old ones, so existing code keeps working and nothing user-facing flips yet.
   - **Phase 2 (the big 33-file refactor):** point every admin view, dashboard widget, signup/
     donation form, email segment, CSV export, REST endpoint (`/supporters`, `/giving`,
     `/memberships` with 410 shims for old), and shortcode internals at the Supporter tables.
     Backwards-compat hooks (`npmp_supporter_*` alongside `npmp_member_*`/`npmp_donor_*`) and
     tokens (`[supporter_name]`) for one minor version. Raises PHP floor to 8.1.
   - **Phase 3 (Pro v3.0.0):** recurring Stripe donations, lapsed/renewal automation, advanced
     segmentation, QB-formatted CSV, donation-summary PDFs (Dompdf), daily subscription-state
     reconciliation cron. IRS-compliant receipts explicitly deferred to v3.1 (legal review).
   - **Phase 4 (validation + ship):** migrate CACC (live, voluntary-donate) to v3 dev and verify
     no data loss by pre/post row counts; onboard RCCTA with a dues config to validate the dues
     half; wp.org beta channel (`nonprofit-manager-beta` slug or self-hosted update server, no
     native beta channel), fallback: ship anyway with rollback featured if <2 beta testers in 2
     weeks. **Phases 4's CACC/RCCTA steps need Eric** (live-data dogfood), so this arc can't be
     fully autonomous. PHPCS-WPCS gate treated as a build failure per the doc.
5. **Growth, not engineering, is the real bottleneck right now.** wp.org listing had under 10
   active installs and 0 ratings as of the last check, months after launch. The product is
   feature-rich and actively maintained. The gap is discovery and social proof. A 7-play growth
   plan exists with plays 1 through 3 done (listing/ASO rewrite, screenshots, content blocks)
   and plays 4 through 7 open (content cluster, a CACC case study, roundup outreach,
   community/YouTube). Worth raising with Eric before assuming more engineering is the
   highest-value next move.

## Testing approach that worked this session (carry forward)

No local WP dev environment was used. Instead:
- **Schema/SQL validation:** run the exact `CREATE TABLE`/`INSERT`/`SELECT` SQL against the real
  cloudpanel MySQL via `wp db query` with a throwaway table name, drop it after. Catches real
  syntax errors dbDelta would otherwise silently mangle.
- **Logic unit tests:** extract the actual function body verbatim (`sed`) from the real file,
  stub the handful of WordPress functions it calls (`get_option`, `$wpdb` methods, and so on)
  with an in-memory fake, assert on behavior. Proves the real code, not a reimplementation of
  it. Used a real SQLite-backed fake `$wpdb` for one test to exercise real SQL rather than
  mocked results.
- **Live end-to-end for anything with real infra behind it** (R2 upload, Cloudflare Worker
  deploy, wp.org SVN release). Always verify with the real API/endpoint after deploying, not
  just "the deploy command succeeded." SHA-256-diff any file that got uploaded somewhere against
  the local original.
- Standing rule already in Eric's global CLAUDE.md, still applies here: fix bugs on sight, take
  safe performance wins, bump safe dependency updates, always verify with tests before calling
  something done.

## How to resume in a fresh conversation

Point Claude at this file (`~/Code/nonprofit-manager/HANDOFF.md`) instead of re-explaining
context. If Eric names one of the "known gaps" above, that's the next unit of work, no further
scoping needed since the target (existing idle table, or a specific unverified assumption) is
already identified.
