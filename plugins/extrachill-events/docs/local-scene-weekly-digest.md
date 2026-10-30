# Local Scene Weekly Digest

The weekly digest is an explicit opt-in to both a weekly email and its in-app update through the Events-owned `local_scene_digest/location/<slug>` entity subscription. Events registers that entity-to-taxonomy mapping through `extrachill_users_entity_subscription_entities`; the generic Users layer does not know about this product. Saving Local Scene does not create a subscription. The location archive renders a read-only status check followed by a user-triggered subscribe/unsubscribe toggle only when that archive is the user's current Local Scene.

## Delivery

The `extrachill_local_scene_digest` Data Machine SystemTask runs weekly when `extrachill_local_scene_digest_enabled` is enabled in Data Machine's `PluginSettings` store. It declares mutation and dry-run support and accepts only bounded `days` (1-14), `limit` (1-20), and `dry_run` parameters. The default-disabled schedule uses a seven-day window, eight-event cap, and explicit `dry_run=false`. It does not use WP-Cron.

The v1 task is intentionally one bounded, single-step scan rather than a fan-out workflow. Each city query currently hydrates at most 250 candidates (configurable within the hard 20-1000 range) after requesting one overflow row to detect truncation. Retryable aggregate failures fail the Data Machine job so its existing whole-task retry can replay safely through notification receipts; this PR does not add a speculative fan-out substrate.

For each canonical selectable location term, Events:

- queries published posts through `data_machine_events_query_events()` and hydrates each through `data_machine_events_parse_event_data()`;
- queries a safe cross-timezone date envelope with a configurable, hard-bounded per-city candidate cap, then requires the exact location term and hard-gates each event into the rolling `days` window from venue-local now using its valid IANA `venueTimezone`;
- records aggregate query failure or truncation evidence when the canonical result reports more events than the bounded fetch returned, without recording city identity;
- rejects unknown/midnight starts, invalid timezones, unsupported statuses, malformed explicit ends, and ends that are not later than their starts; rendered start/end times remain venue-local;
- collapses local duplicates by normalized title, venue term, and local start date;
- orders Going events first for each recipient, then priority event, priority venue, completeness, known price/free status, datetime, and post ID;
- caps repeated venues and performers when enough inventory exists, sends all qualified sparse inventory, and sends nothing for an empty scene;
- resolves only explicit digest subscribers and rechecks that the canonical location slug still equals `extrachill_users_get_local_scene()` at execution time;
- resolves Going attendance only against the canonical Events blog returned by `ec_get_blog_id( 'events' )` and fails closed if that identity is unavailable.

`ec_users_notify_with_receipts()` claims one delivery per user, ISO week, and location with `producer_owns_email=true`, which suppresses the generic Users email sweep. Only an `inserted` receipt for a recipient still eligible under the master email preference queues the branded email through `ec_send_email_queued()`; an `existing` replay and a master-suppressed delivery never queue or release. Explicit rich-email preflight or queue-admission failures release the exact notification receipt through `ec_users_release_notification_receipt()` so a later full replay can retry. The network bot is the notification actor.

Task evidence contains aggregate counts and stable generic failure reasons only, including released notification receipts and bounded-query truncation. It never includes recipient identity, location identity, or event identity.

## Unsubscribe

Every email includes a location-specific REST unsubscribe URL signed with the WordPress auth salt and valid for at most 30 days. GET verifies the signature and renders a standalone noindex confirmation form without mutating consent, making scanner visits safe. The form POSTs the same signed payload; only that POST removes `local_scene_digest/location/<slug>` through Extra Chill Users. It stops both the weekly email and its in-app update without changing the user's saved Local Scene or global notification-email preference. Events continues to rely on the Users #294/#297 producer-owned email and unsubscribe contracts and never accesses Users tables directly.
