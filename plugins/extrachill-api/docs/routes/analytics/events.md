# Analytics Events Endpoints

## Routes
- `GET /wp-json/extrachill/v1/analytics/events`
- `GET /wp-json/extrachill/v1/analytics/events/summary`
- `GET /wp-json/extrachill/v1/analytics/meta`

## Purpose
These endpoints expose **network-wide** analytics events stored in the Extra Chill Analytics plugin’s network table.

They are intended for **network admin** reporting (capability: `manage_network_options`).

## Storage
Events are stored in the network table returned by:
- `extrachill_analytics_events_table()`

Table name (default multisite prefixing):
- `{base_prefix}extrachill_analytics_events`

Columns used by the API:
- `event_type` (string)
- `event_data` (JSON stored as longtext)
- `source_url` (string)
- `blog_id` (int)
- `user_id` (int|null)
- `created_at` (datetime, UTC)

## GET `/analytics/events`

### Permissions
Requires `manage_network_options`.

### Query Parameters
| Param | Type | Required | Notes |
| --- | --- | --- | --- |
| `event_type` | string | No | Filters by a single event type (matches `event_type` exactly). |
| `blog_id` | integer | No | Filters by blog ID. |
| `date_from` | string | No | `YYYY-MM-DD` (interpreted as `00:00:00`). |
| `date_to` | string | No | `YYYY-MM-DD` (interpreted as `23:59:59`). |
| `search` | string | No | Substring match against the JSON `event_data` column. |
| `limit` | integer | No | Defaults to `100`. |
| `offset` | integer | No | Defaults to `0`. |

### Response
```json
{
  "events": [
    {
      "id": 123,
      "event_type": "newsletter_signup",
      "event_data": { "context": "homepage", "list_id": "abc123" },
      "source_url": "https://extrachill.com/...",
      "blog_id": 1,
      "user_id": 456,
      "created_at": "2026-01-07 12:34:56"
    }
  ],
  "count": 1,
  "total": 1
}
```

## GET `/analytics/events/summary`

### Permissions
Requires `manage_network_options`.

### Query Parameters
| Param | Type | Required | Notes |
| --- | --- | --- | --- |
| `event_type` | string | Yes | Event type to aggregate. |
| `days` | integer | No | Defaults to `30`. `0` means all time. |
| `blog_id` | integer | No | Optional blog filter. |

### Response
Matches `extrachill_get_analytics_event_stats()`:
- `total` (int)
- `by_date` (array of `{ date, count }`)
- `by_source` (array of `{ source_url, count }`)
- `by_context` (array of `{ context, count }`, extracted from `event_data.context`)

## Related documentation
- [Analytics Meta Endpoint](meta.md)
- [Async View Count Endpoint](view-count.md)
- [Click Analytics Endpoint](click.md)
