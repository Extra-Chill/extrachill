# Upcoming Event Counts Endpoint

## Route
`GET /wp-json/extrachill/v1/events/upcoming-counts`

## Purpose
Returns counts of upcoming events (events with `_datamachine_event_datetime` >= today) for taxonomy terms on the events site (Blog ID resolved via `ec_get_blog_id( 'events' )`).

This endpoint is used by cross-site taxonomy linking and other consumers that need consistent upcoming-event counts.

## Authentication
Public (`permission_callback` is `__return_true`).

## Query Parameters
| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `taxonomy` | string | Yes | Taxonomy slug. Limited to: `venue`, `location`, `artist`, `festival`. |
| `slug` | string | No | If provided, returns a single term result for the given term slug. |
| `limit` | integer | No | Max results for bulk requests (default `8`, max `50`). |

## Response
### Single term response (`slug` provided)
Returns a single object or `null`.

```json
{
  "slug": "some-slug",
  "name": "Some Name",
  "count": 3,
  "url": "https://events.extrachill.com/festival/some-slug/"
}
```

### Bulk response (`slug` omitted)
Returns an array of term objects, sorted by count descending, truncated to `limit`.

## Error Responses
- `500 events_site_unavailable` when the events ID cannot be resolved (requires `ec_get_blog_id()`).

## Notes
Counting is performed in events context using `WP_Query` against `post_type=datamachine_events` with:
- `tax_query` for the term
- `meta_query` on `_datamachine_event_datetime` with `compare: >=`
