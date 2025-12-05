# Link Page Analytics Endpoint

## Route
`GET /wp-json/extrachill/v1/analytics/link-page`

## Purpose
Returns aggregated performance metrics for a single artist link page so roster members can review traffic without leaving the editor experience.

## Request Params
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `link_page_id` | integer | Yes | Must reference an `artist_link_page` post. Fails with `400` if the post type is wrong or missing. |
| `date_range` | integer | No | Number of trailing days to report (default `30`). Accepts positive integers only. |

## Permission Model
- Endpoint requires an authenticated request (cookie-auth or application password).
- `ec_can_manage_artist( current_user, artist_id )` must return `true`; otherwise the response is `403 permission_denied`.
- The handler resolves the owning artist ID via `apply_filters( 'ec_get_artist_id', $link_page_id )` before capability checks.

## Response Contract
When analytics data is available, the endpoint returns whatever payload the `extrachill_get_link_page_analytics` filter supplies. Typical properties include:
```json
{
  "views": 1240,
  "clicks": 312,
  "top_links": [
    { "label": "Instagram", "clicks": 140 },
    { "label": "YouTube", "clicks": 95 }
  ],
  "timeseries": [
    { "date": "2025-01-01", "views": 120, "clicks": 32 }
  ]
}
```
If no data provider responds or a handler returns `WP_Error`, the request fails with either `500 analytics_unavailable` or the bubbled error from the filter.

## Processing Flow
1. Validates args through REST schema and verifies the post represents an artist link page.
2. Resolves the associated artist ID so permission checks are tied to roster ownership.
3. Requires a managing user via `ec_can_manage_artist` before exposing analytics.
4. Calls `apply_filters( 'extrachill_get_link_page_analytics', null, $link_page_id, $date_range )` to fetch data from the analytics provider (usually the artist platform plugin).
5. Returns the filter result verbatim on success.

## Error Conditions
| Code | HTTP | Explanation |
| --- | --- | --- |
| `invalid_link_page` | 400 | `link_page_id` missing or not an `artist_link_page` post. |
| `artist_not_found` | 400 | No artist ID mapped from the link page. |
| `permission_denied` | 403 | Current user cannot manage the associated artist. |
| `analytics_unavailable` | 500 | Provider failed to return analytics data. |

## Consumer Notes
- Only authenticated roster managers can fetch analytics; anonymous dashboards must proxy through a secure backend.
- Use native REST auth (nonce or application password) when calling from the WordPress admin or block editor.
- Because the payload is fully provider-driven, be prepared to handle additional metrics or nested objects introduced by the analytics plugin.
