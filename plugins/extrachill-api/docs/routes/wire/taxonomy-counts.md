# Wire Taxonomy Counts Endpoint

## Route
`GET /wp-json/extrachill/v1/wire/taxonomy-counts`

## Purpose
Returns post counts and term archive URLs for taxonomy terms on the wire site (Blog ID resolved via `ec_get_blog_id( 'wire' )`).

This endpoint is used by cross-site taxonomy linking and other consumers that need counts without manual `switch_to_blog()` logic.

## Authentication
Public (`permission_callback` is `__return_true`).

## Query Parameters
| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `taxonomy` | string | Yes | Taxonomy slug to query. The handler checks `taxonomy_exists()` in the wire context. |
| `slug` | string | No | If provided, returns a single term result for the given term slug. |
| `limit` | integer | No | Max results for bulk requests (default `8`, max `50`). |

## Response
### Single term response (`slug` provided)
Returns a single object or `null`.

```json
{
  "slug": "some-slug",
  "name": "Some Name",
  "count": 12,
  "url": "https://wire.extrachill.com/location/some-slug/"
}
```

### Bulk response (`slug` omitted)
Returns an array of term objects, sorted by count descending, truncated to `limit`.

## Error Responses
- `500 wire_site_unavailable` when the wire ID cannot be resolved (requires `ec_get_blog_id()`).

## Notes
Counting is performed in wire context using `WP_Query` + `tax_query` and returns `found_posts`.
