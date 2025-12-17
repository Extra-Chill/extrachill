# Shop Catalog Endpoint

## Route
`GET /wp-json/extrachill/v1/shop/catalog`

## Purpose
Public product catalog for shop homepage filtering and sorting. Returns published products with pagination, artist filter options, and view counts.

## Permission Model
- Public access (no authentication required)
- Read-only endpoint

## Query Parameters

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `artist` | string | - | Artist taxonomy slug to filter by |
| `sort` | string | `recent` | Sort order (see options below) |
| `page` | int | `1` | Page number for pagination |
| `per_page` | int | `12` | Products per page (max 100) |

### Sort Options
| Value | Description |
| --- | --- |
| `recent` | Newest first (default) |
| `oldest` | Oldest first |
| `price-asc` | Price low to high |
| `price-desc` | Price high to low |
| `random` | Random order |
| `popular` | Most views first |

## Response Schema

```json
{
  "products": [
    {
      "id": 456,
      "title": "Album Name",
      "permalink": "https://shop.extrachill.com/product/album-name/",
      "image": "https://shop.extrachill.com/wp-content/uploads/.../cover.jpg",
      "price_html": "<span class=\"woocommerce-Price-amount\">$9.99</span>",
      "artists": [
        {
          "name": "Artist Name",
          "url": "https://shop.extrachill.com/artist/artist-slug/"
        }
      ],
      "rating_html": "<div class=\"star-rating\">...</div>",
      "add_to_cart": {
        "url": "?add-to-cart=456",
        "text": "Add to cart"
      },
      "views": 123
    }
  ],
  "pagination": {
    "total": 48,
    "pages": 4,
    "current": 1,
    "per_page": 12
  },
  "artists": [
    {
      "slug": "artist-slug",
      "name": "Artist Name"
    }
  ]
}
```

### Response Fields

**products[]**
- `id` - Product ID
- `title` - Product name
- `permalink` - Product URL
- `image` - Featured image URL (empty string if none)
- `price_html` - Formatted price with currency
- `artists` - Array of artist taxonomy terms
- `rating_html` - Star rating HTML (empty if no ratings)
- `add_to_cart` - Add to cart button data
- `views` - View count from analytics

**pagination**
- `total` - Total product count
- `pages` - Total pages
- `current` - Current page
- `per_page` - Items per page

**artists**
- List of all artists with products (for filter dropdown)

## Example Requests

### Basic
```bash
curl "https://shop.extrachill.com/wp-json/extrachill/v1/shop/catalog"
```

### With Filters
```bash
curl "https://shop.extrachill.com/wp-json/extrachill/v1/shop/catalog?artist=artist-slug&sort=price-asc&page=2"
```

## Error Codes

| Code | Status | Description |
| --- | --- | --- |
| `dependency_missing` | 500 | WooCommerce unavailable |
| `configuration_error` | 500 | Shop blog ID not configured |

## Implementation Notes
- Executes within shop blog context via `switch_to_blog()`
- View counts retrieved from `ec_post_views` meta key
- Artist taxonomy: `ec_artist` (registered by extrachill-shop)
- Price sorting uses `_price` meta key with numeric comparison
- Popular sorting uses `ec_post_views` meta key
