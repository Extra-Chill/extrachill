# Public calendar feed

Fetch events from the Extra Chill calendar for a public app or directory. No account or API key is required. If you're displaying one venue's schedule on its own website, use the [keyed Partner API](api.md) instead.

## Request

```http
GET https://events.extrachill.com/wp-json/extrachill/v1/events/calendar?event_type=open-mic&page=1
```

This example selects the `open-mic` event type, which includes open mics and jams. Use the Events host shown above.

| Parameter | Type | Meaning |
|---|---|---|
| `page` | integer | Page number, starting at 1 (the default). |
| `venue` | string | Venue slug. |
| `promoter` | string | Promoter slug. |
| `location` | string | Location slug. |
| `event_type` | string | Event type slug, such as `open-mic`. |
| `scope` | string | One of `today`, `tonight`, `this-weekend`, or `this-week`. |
| `lat` | number | Latitude for a nearby search; supply both coordinates. |
| `lng` | number | Longitude for a nearby search; supply both coordinates. |
| `radius` | integer | Nearby search radius in miles; defaults to 25. |
| `search` | string | Free-text search query. |
| `past` | boolean | Request past events with `true`; defaults to `false` (upcoming events). |

All parameters are optional. Slug filters use the slug, not the display name or numeric ID. An unrecognized slug is ignored, so check that you're using the intended slug rather than relying on an empty result.

Start at page 1. While `has_more` is `true`, request `page + 1` with the same filters. There is no page-size parameter. For a refresh, start at page 1 again: events may move between pages when the calendar changes.

## Response

| Field | Type | Meaning |
|---|---|---|
| `dates` | array of objects | Date groups for the current page. Each group has a `date` (`YYYY-MM-DD`), a human-readable `label`, and an `events` array. An empty `dates` array is a successful response. |
| `total` | integer | Event count for the result set, not just this page. |
| `page` | integer | Current page number. |
| `has_more` | boolean | Whether another page is available. |

### Event fields

| Field | Type | Meaning |
|---|---|---|
| `id` | integer | Extra Chill event ID. It stays the same when the event's date or time changes. |
| `title` | string | Event title. |
| `datetime` | string | Start date and time (`YYYY-MM-DDTHH:mm:ss`, without an offset), or an empty string if unavailable. |
| `end_datetime` | string or null | End date and time in the same format, if available. |
| `venue` | object or null | Venue details, including `id`, `name`, `slug`, address fields, coordinates, and `timezone` when available. |
| `organizer` | object or null | Organizer details, such as `name` and `url`. |
| `performer` | object or null | Performer details, such as `name` and `type`. |
| `taxonomies` | object | Terms grouped by taxonomy (for example, `artist`, `location`, and `promoter`), with `term_id`, `name`, and `slug` per term. |
| `status` | string | Schema.org event status, such as `EventScheduled` or `EventRescheduled`. |
| `occurrence_context` | object | Context for this date, such as `display_date`, `is_multi_day`, and `is_continuation`. |
| `occurrence_display` | object | Display values for this occurrence, such as `formatted_time_display` and `multi_day_label`. |
| `permalink` | URL string | The event page on Extra Chill. |
| `source` | object or null | Original listing, with `url` and `name` when supplied upstream. Expect `null` when attribution data isn't available yet. |
| `ticket_url` | URL string or null | The supplied ticket destination, if available for direct linking. |
| `ticket_ref` | integer, optional | Extra Chill's ticket redirect reference, supplied when the direct affiliate URL is withheld. |

Use `id` to update an existing event instead of creating a new record when it moves. A multi-day event can appear under several dates with the same `id`; use the date group as well if you're storing individual calendar entries.

## Attribution and tickets

Credit Extra Chill by linking each listing to its supplied `permalink`. When `source.url` is present, also link to the original listing, using `source.name` as the label if available.

Use a non-null `ticket_url` exactly as returned. A `ticket_ref` is a reference for Extra Chill's on-site ticket redirect, not a public ticket URL. If you receive a `ticket_ref` instead of a `ticket_url`, link to the supplied `permalink` and let Extra Chill handle the ticket click. Don't rewrite ticket links or references, resolve them in bulk, or substitute a seller's direct URL. `source.url` is for attribution, not ticketing.

## Polling and caching

Poll from a shared server-side job rather than fetching on every visitor's page load. Cache each page by its full set of query parameters and respect any cache headers the server sends. Start with a refresh every 15 minutes or less often, and fetch only the pages and filters you need.

Treat non-2xx responses as errors, not empty calendars. On `429` or transient server errors, honor `Retry-After` when present and back off before retrying. Keep serving your last successful snapshot during a temporary outage.
