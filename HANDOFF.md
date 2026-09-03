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

## Current state (2026-09-03: Free and Pro are both live at `2026.09.6`)

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

Known gap left in place: `create_dues_subscription()` still ignores a failed Stripe cancel of
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
