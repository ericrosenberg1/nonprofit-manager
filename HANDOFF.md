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
  D1, R2, and KV, live at `https://nonprofitmanager.ericrosenberg.com`. Handles license
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

## Current state (2026.07.4, shipped 2026-07-13)

Both plugins are at **2026.07.4**, confirmed live everywhere: wp.org API, license server
`/api/license/version`, and a byte-identical Pro download. All 6 items from the original
security/perf/UX review that prompted this round are now fixed (5 in Free, 1 in Pro):

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

Ranked by how directly they affect a real user, not by effort.

1. **Newsletter SEND queue is still `wp_posts`-based**, distinct from open/click tracking, which
   was just fixed. `class-newsletter-manager.php`'s `queue_newsletter()`/`process_queue()` use
   a `wp_insert_post` custom post type (`npmp_nl_queue`, one post per queued recipient) instead
   of the `wp_npmp_newsletter_queue` custom table that already exists in the schema
   (`activation-hooks.php` creates it) and sits completely unused. Same situation opens/clicks
   were in before the 2026.07.4 fix. This is the natural next migration: same pattern, same
   target table already built, just needs the queue/process/reports code rewired from
   `WP_Query` plus postmeta to `$wpdb` calls against the real table.
2. **Social Sharing admin page 403**, reported once (2026-07-04, on a Local dev site with Pro
   temporarily disabled). `admin.php?page=npmp_social_sharing` returned "Sorry, you are not
   allowed to access this page" for a logged-in admin, despite `admin-social.php` registering
   the submenu with a plain `manage_options` capability check that looks correct on read.
   **Not reproduced this session.** Worth a quick live check before assuming it's still broken,
   could have been fixed incidentally or been environment-specific.
3. **Pro's import row-cap removal is unverified for the write-side fix.** Free's 2026.07.4 fix
   bounded memory during CSV/XLSX *parsing*, and the DB-write side was already capped at 50 rows
   in Free. Pro removes that cap via the `npmp_import_max_rows` filter for real imports. If
   Pro's write loop isn't separately chunked, a large paying customer's import could still hit
   `max_execution_time` on the write side even with parsing fixed. Pro's import code wasn't
   audited this round.
4. **Major planned direction, not started: unified "Supporter" data model.** Collapse member
   and donor into one record, the stated differentiator vs. other WP nonprofit plugins. Ships in
   Free (it's foundational), Pro layers Stripe/recurring/segmentation/automation/QB-export/PDF
   summaries on top. Design doc:
   `/Users/eric/.gstack/projects/ericrosenberg1-nonprofit-manager-site/eric-main-design-20260502-075243.md`
   (status: approved). Large, multi-phase effort, not a quick fix. RCCTA (a second dogfood
   nonprofit, dues-based) is the intended validation target for the dues half of the model
   before it ships.
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
