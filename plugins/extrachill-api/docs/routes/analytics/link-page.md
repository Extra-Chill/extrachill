# Link Page Analytics Endpoint

## Route
`GET /wp-json/extrachill/v1/analytics/link-page`

## Purpose
Returns aggregated performance metrics for a single artist link page so roster members can review traffic without leaving the editor experience.

## Request Params
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `link_page_id` | integer | Yes | Must reference an `ec_link_page` post. Fails with `400` if the post type is wrong or missing. |
| `date_range` | integer | No | Number of trailing days to report (default `30`). Accepts positive integers only. |

## Permission Model
- Endpoint requires an authenticated request (cookie-auth or application password).
- `ec_can_manage_artist( current_user, artist_id )` must return `true`; otherwise the response is `403 permission_denied`.
- The route is a thin adapter over the `extrachill/get-link-page-analytics` ability (extrachill-analytics). The ability resolves the owning artist from the Link Page's owner reference (`_ec_link_page_owner_reference`) on the Link Page storage blog, then authorizes against that artist on the Artist site.

## Response Contract
When analytics data is available, the endpoint returns whatever payload the `extrachill_get_link_page_analytics` filter supplies.

The current provider (artist platform) returns an object with:
- `summary.total_views` and `summary.total_clicks`
- `chart_data.labels` and `chart_data.datasets` (views + clicks series)
- `top_links[]` entries with `text`, `identifier` (URL), and `clicks`

```json
{
  "summary": { "total_views": 1240, "total_clicks": 312 },
  "chart_data": {
    "labels": ["2026-01-01", "2026-01-02"],
    "datasets": [
      { "label": "Page Views", "data": [120, 98] },
      { "label": "Link Clicks", "data": [32, 21] }
    ]
  },
  "top_links": [
    { "text": "Listen on Spotify", "identifier": "https://open.spotify.com/...", "clicks": 140 }
  ]
}
```
If no data provider responds or a handler returns `WP_Error`, the request fails with either `500 analytics_unavailable` or the bubbled error from the filter.

## Processing Flow
1. Validates args through REST schema.
2. Executes the `extrachill/get-link-page-analytics` ability, which verifies the Link Page on its storage blog, resolves the owning artist from the Link Page's owner reference, and requires `ec_can_manage_artist` for that artist.
3. The ability reads data through the `extrachill_get_link_page_analytics` filter (provided by extrachill-analytics).
4. Returns the ability result verbatim on success.

## Error Conditions
| Code | HTTP | Explanation |
| --- | --- | --- |
| `invalid_link_page` | 400 | `link_page_id` missing or not an `ec_link_page` post. |
| `artist_not_found` | 400 | No artist ID mapped from the link page. |
| `permission_denied` | 403 | Current user cannot manage the associated artist. |
| `analytics_unavailable` | 500 | Provider failed to return analytics data. |
| `ability_not_found` | 500 | The extrachill-analytics ability is not registered. |

## Consumer Notes
- Only authenticated roster managers can fetch analytics; anonymous dashboards must proxy through a secure backend.
- Use native REST auth (nonce or application password) when calling from the WordPress admin or block editor.
- Because the payload is fully provider-driven, be prepared to handle additional metrics or nested objects introduced by the analytics plugin.
