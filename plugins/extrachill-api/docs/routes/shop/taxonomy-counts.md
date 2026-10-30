# Shop Taxonomy Counts Endpoint

## Route
`GET /wp-json/extrachill/v1/shop/taxonomy-counts`

## Purpose
Returns product counts and term archive URLs for taxonomy terms on the shop site (Blog ID resolved via `ec_get_blog_id( 'shop' )`).

This endpoint is used by cross-site taxonomy linking and artist-facing surfaces that need consistent counts.

## Authentication
Public (`permission_callback` is `__return_true`).

## Query Parameters
| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `taxonomy` | string | Yes | Taxonomy slug to query. Currently restricted to `artist`. |
| `slug` | string | No | If provided, returns a single term result for the given term slug. |
| `limit` | integer | No | Max results for bulk requests (default `8`, max `50`). |

## Response
### Single term response (`slug` provided)
Returns a single object or `null`.

```json
{
  "slug": "artist-slug",
  "name": "Artist Name",
  "count": 4,
  "url": "https://shop.extrachill.com/artist/artist-slug/"
}
```

### Bulk response (`slug` omitted)
Returns an array of term objects, sorted by count descending, truncated to `limit`.

## Error Responses
- `500 shop_site_unavailable` when the shop ID cannot be resolved (requires `ec_get_blog_id()`).

## Notes
Counting is performed in shop context using `WP_Query` against `post_type=product` and returns `found_posts`.
