# Async View Count Endpoint

## Route
`POST /wp-json/extrachill/v1/analytics/view`

## Purpose
Captures post view events asynchronously. Useful for blocks and templates that want lightweight analytics without blocking page rendering.

## Request Body
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `post_id` | integer | Yes | Target WordPress post ID. Must be a positive integer. |
| `referrer` | string | No | Browser referrer. Analytics normalizes this to a host-only value. |

## Processing Steps
1. Validates the payload via REST schema.
2. Confirms the Analytics-owned `extrachill/track-page-view` ability is available. Returns `500 ability_not_found` if unavailable.
3. Executes the ability to increment counters and record the pageview.

Visitor identity is never accepted from the browser payload. The Analytics-owned pageview ability resolves an existing first-party `ec_vid` cookie or mints one only on its early pageview path. GPC/DNT opt-out and cross-site requests without that cookie remain anonymous.

**Note**: This endpoint is public (`permission_callback` is `__return_true`). If the post type is `artist_link_page`, it also fires `extrachill_link_page_view_recorded`.

## Response
```
{ "recorded": true }
```

## Usage Pattern
Send the request via `fetch` after the page loads:
```js
fetch('/wp-json/extrachill/v1/analytics/view', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ post_id: 123 })
});
```
