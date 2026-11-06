# Upcoming shows Link Page source

Events registers `upcoming_events` when Link Pages is available. A managed
section uses `type: upcoming_events`, `source_config: { limit: 5 }`, and starts
with `links: []`. Limits are clamped to 1–25. The owner must be an artist-profile
post on the artist site with a canonical `_artist_term_id` binding.

The resolver uses the existing canonical artist events adapter (upcoming scope,
chronological ordering owned by the events query). It stores only date/venue/title
link labels and canonical event URLs, not event records. Adapter failures return
WP_Error so Link Pages retains its last successful snapshot; zero upcoming shows
returns an empty list to clear the section.

Event saves, deletes, artist taxonomy changes and metadata writes coalesce to a
shutdown refresh after the final event state is persisted. All opted-in sections
are refreshed, including artists removed from an event. An hourly Events-site
WP-Cron push removes passed shows even without edits (subject to normal WP-Cron
traffic/delivery delays). Rendering never queries Events.

## Remaining work

This is the source/lifecycle portion of #1177. The shared Link Pages editor still
only creates manual sections: it needs source selection, read-only managed-link
controls and a first-refresh operation after creation. Until that UI lands,
provisioning must use the existing managed-section save and refresh APIs. No new
unauthenticated mutation endpoint is added here.

Inline YouTube rendering already exists; no changes are made to it. Dedicated
video cards beyond that embed are not built. Press/quote cards are tracked in
Extra-Chill/extrachill#1187 and photo strips in Extra-Chill/extrachill#1188.

## Test

`php plugins/extrachill-events/tests/link-page-upcoming-shows-smoke.php`

This standalone test uses the real section registry with WordPress boundary
stubs. It covers owner binding, limits, links, errors, empty results, context
restoration, event lifecycle hooks, refresh coalescing and the hourly schedule.
