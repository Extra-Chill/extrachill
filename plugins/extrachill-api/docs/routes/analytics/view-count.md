# Async View Count Endpoint

## Route
`POST /wp-json/extrachill/v1/analytics/view`

## Purpose
Captures post view events asynchronously. Useful for blocks and templates that want lightweight analytics without blocking page rendering.

## Request Body
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `post_id` | integer | Yes | Target WordPress post ID. Must be a positive integer. |

## Processing Steps
1. Validates the payload via REST schema.
2. Confirms `ec_track_post_views()` exists (provided by the `extrachill-analytics` plugin). Returns `500 function_missing` if unavailable.
3. Calls `ec_track_post_views( $post_id )` to increment counters.

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
