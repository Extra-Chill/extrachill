# Background OG cards (#1795)

## Decisions / PR notes

- Call `DataMachine\Abilities\Media\ImageTemplateAbilities::renderTemplate()` directly, the implementation wrapped by the public ability. This is a trusted internal caller; public ability permissions are unchanged. No impersonation or permission filters.
- Drop lazy rendering entirely. SEO reads only current cached cards; misses/stale cards return the existing filter value. Both Open Graph and Event JSON-LD already consume this filter, so no SEO change is needed.
- Save, post-meta and taxonomy-assignment hooks coalesce into one site-local WP-Cron event per post, delayed 30 seconds. The callback inspects final data (including importer writes after `save_post`) and dispatches the existing `og_card_generation` SystemTask only when needed. Scheduler failures requeue. WP-Cron and Data Machine workers must run normally.
- Preserve old files until successful replacement; stale cards are not served. Featured images and the enabled toggle retain precedence. Unchanged signatures do not render again.
- No third-party image hotlinking or changes to `include_images`. Generated-card storage/GD costs remain the issue's tradeoff for Chubes.
- Existing events require the explicit backfill below; it has **not** been run on production. Venue-term attribute changes without reassignment can be picked up by a subsequent backfill scan.

## Bounded backfill

Run in the events site's context, initially with `--dry-run`:

```sh
wp --url=https://events.extrachill.com extrachill og-cards backfill --limit=100 --dry-run
wp --url=https://events.extrachill.com extrachill og-cards backfill --limit=100
# Continue with the returned cursor, until "scan complete":
wp --url=https://events.extrachill.com extrachill og-cards backfill --limit=100 --after=12345
```

Each call scans at most 1000 published event IDs, filters upcoming events using the public event-date API, skips featured images/current cards, and queues missing/stale cards. Cursors advance across skipped records too. Repeat scans are safe. Allow cron and task workers to drain between batches; dry-run performs no scheduling or rendering.

## Narrow checks

- `php tests/og-card-background-smoke.php` (includes content-addressed cache regression tests; tests anonymous internal rendering, publish/metadata queue coalescing, SystemTask dispatch, cache hit/miss/stale behavior).
- `php tests/og-card-regeneration-ability-smoke.php`
- `php -l inc/og-cards/backfill-command.php`

Full Homeboy test/lint gates are intentionally left to Homeboy.
