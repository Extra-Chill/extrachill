# Shop Products Endpoint

## Routes
- `GET /wp-json/extrachill/v1/shop/products`
- `POST /wp-json/extrachill/v1/shop/products`
- `GET /wp-json/extrachill/v1/shop/products/{id}`
- `PUT /wp-json/extrachill/v1/shop/products/{id}`
- `DELETE /wp-json/extrachill/v1/shop/products/{id}`

## Purpose
WooCommerce product CRUD for artists. Products are stored on the shop site and linked to artist profiles via product meta key `_artist_profile_id`.

## Permission Model
- All routes require a logged-in user.
- Collection routes require the user to manage at least one artist (admins allowed).
- Item routes require the user to manage the artist that owns the product (admins allowed).

## Response Schema (Product)
Returned by list, get, create, and update.

```json
{
  "id": 456,
  "name": "Album Name",
  "description": "<p>Full description HTML</p>",
  "short_description": "<p>Short description HTML</p>",
  "price": "9.99",
  "sale_price": "7.99",
  "manage_stock": true,
  "stock_quantity": 100,
  "status": "pending",
  "permalink": "https://shop.extrachill.com/product/album-name/",
  "artist_id": 123,
  "image": {
    "id": 789,
    "url": "https://shop.extrachill.com/wp-content/uploads/.../cover.jpg"
  },
  "gallery": [
    {
      "id": 790,
      "url": "https://shop.extrachill.com/wp-content/uploads/.../gallery-1.jpg"
    }
  ]
}
```

Notes:
- `description` and `short_description` may contain HTML.
- `image.url` is an empty string if no image is set.
- `gallery` is an array of `{ id, url }` objects.

## GET List

### Request
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/shop/products"
```

### Response
Returns an array of product objects.

```json
[
  {
    "id": 456,
    "name": "Album Name",
    "price": "9.99",
    "manage_stock": true,
    "stock_quantity": 100,
    "artist_id": 123,
    "image": {"id": 789, "url": "https://..."},
    "gallery": []
  }
]
```

## GET Single

### Request
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/shop/products/456"
```

### Response
Returns a single product object.

## POST Create

### Body
```json
{
  "artist_id": 123,
  "name": "New Album",
  "price": 12.99,
  "sale_price": 9.99,
  "description": "<p>Full description</p>",
  "short_description": "<p>Short description</p>",
  "manage_stock": true,
  "stock_quantity": 50,
  "image_id": 789,
  "gallery_ids": [790, 791]
}
```

Notes:
- `artist_id`, `name`, and `price` are required.
- `sale_price` is applied only when it is `> 0` and less than `price`.
- `gallery_ids` is truncated to max 4 images.
- New products are created with status `pending`.

### Response
Returns the created product object.

## PUT Update

### Body
All fields are optional. Fields omitted are not changed.

```json
{
  "name": "Updated Album Name",
  "price": 14.99,
  "sale_price": 10.99,
  "manage_stock": true,
  "stock_quantity": 75,
  "image_id": 0,
  "gallery_ids": []
}
```

Notes:
- `image_id: 0` clears the featured image.
- `gallery_ids` is truncated to max 4 images.
- Changing `artist_id` only succeeds if the user can manage the target artist.

### Response
Returns the updated product object.

## DELETE

### Request
```bash
curl -X DELETE "http://site.local/wp-json/extrachill/v1/shop/products/456"
```

### Response
```json
{
  "deleted": true,
  "product_id": 456
}
```

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `rest_forbidden` | 401 | Must be logged in |
| `rest_forbidden` | 403 | Not an artist / cannot manage product |
| `invalid_artist` | 403 | Cannot manage specified artist |
| `product_not_found` | 404 | Product not found |
| `dependency_missing` | 500 | WooCommerce unavailable |
| `configuration_error` | 500 | Shop blog ID not configured |
| `create_failed` | 500 | Failed to create product |
| `delete_failed` | 500 | Failed to trash product |

## Implementation Notes
- Handlers `switch_to_blog()` into the shop blog internally.
- Product ownership is stored in `_artist_profile_id` post meta.
- Images are uploaded via `POST /wp-json/extrachill/v1/media` with `context: product_image`.
