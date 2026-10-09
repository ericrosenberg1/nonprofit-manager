# Sentry: Nonprofit Manager (WP plugin)

Project: `rosenberg-digital/nonprofit-manager-wp` · platform `php`

## Design: piggyback, don't bundle

Nonprofit Manager is distributed via wp.org. It **does not bundle** the
Sentry PHP SDK because:

1. Bundling adds ~500 KB to every install.
2. Sending data to a third-party Sentry org without explicit user opt-in
   would violate the wp.org plugin guidelines.

Instead, NPMP **detects** whether [WP-Sentry-Integration](https://wordpress.org/plugins/wp-sentry-integration/)
is active and, if so, adds NPMP-specific tags + context to whatever Sentry
client the host site has already configured.

## What's wired up

`includes/npmp-sentry.php` (loaded from the main plugin file):

- On `init`, calls `wp_sentry_safe()` (the WP-Sentry plugin's public entry
  point). If WP-Sentry isn't active, this is a no-op.
- Adds tag `plugin = nonprofit-manager` to every event so the host can
  filter NPMP errors in their Sentry dashboard.
- Adds context block `nonprofit_manager` with `version` and `features`.

There is no capture helper. An earlier version of this file described an
`npmp_sentry_capture()` function that was never written, so NPMP code reports
nothing on its own. Uncaught errors still reach Sentry through WP-Sentry's own
error handler, tagged as above.

## Is anything reporting?

No, as of 2026-10-09. The `nonprofit-manager-wp` project has never had an
issue, and Sentry's stats_v2 shows zero accepted, filtered or discarded events over
90 days. Neither the cloudpanel test site nor CACC runs WP-Sentry-Integration
or defines `WP_SENTRY_PHP_DSN`. An empty issue list here means no install is
wired to report, not that the plugin is error free. Install WP-Sentry on CACC
with the DSN below to change that.

## For Eric's own NPMP-using sites

To route NPMP errors specifically into the `nonprofit-manager-wp` Sentry
project (instead of whatever DSN the host site uses), set the host's
WP-Sentry-Integration `WP_SENTRY_PHP_DSN` to the NPMP project DSN:

```php
define( 'WP_SENTRY_PHP_DSN', 'https://7e2558c74621ddf8f8f0f1ca68f53ced@o4507525754060800.ingest.us.sentry.io/4511429521244160' );
```

That sends *all* errors from that site to the NPMP project, fine for a
site whose only purpose is running NPMP (e.g. a dedicated nonprofit site).

For multi-purpose sites that also need their own Sentry project, use the
site's own DSN; NPMP errors will appear there with the `plugin=nonprofit-manager`
tag, easy to filter on.

## Release tracking

```sh
sentry release new --org rosenberg-digital --project nonprofit-manager-wp \
    "nonprofit-manager@$(grep '^ \* Version:' nonprofit-manager.php | awk '{print $3}')"
sentry release finalize "nonprofit-manager@..."
```
