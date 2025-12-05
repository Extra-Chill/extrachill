# Link Click Analytics Endpoint

## Route
`POST /wp-json/extrachill/v1/analytics/link-click`

## Purpose
Records outbound clicks from ExtraChill link pages while stripping Google Analytics query cruft, letting analytics dashboards track the canonical destination URLs.

## Request Body
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `link_page_id` | integer | Yes | WordPress post ID for the link page capturing analytics. Must be greater than 0. |
| `link_url` | string | Yes | URL that was clicked. Automatically sanitized and normalized. |

## Processing Steps
1. Validates inputs through REST arg schema.
2. `extrachill_api_normalize_tracked_url()` removes `_gl`, `_ga`, and `_ga_*` parameters so affiliate tags remain untouched.
3. Fires `extrachill_link_click_recorded( $link_page_id, $normalized_url )` for downstream storage.

## Response
```
{ "success": true }
```

## Consumer Responsibilities
- Call immediately after the click occurs (frontend fetch or server-side proxy).
- Rely on hooks to persist analytics; this endpoint does not store data itself.
