# Shop Products Endpoint

## Route
`GET/POST/PUT/DELETE /wp-json/extrachill/v1/shop/products`
`GET/POST/PUT/DELETE /wp-json/extrachill/v1/shop/products/{id}`

## Purpose
Complete WooCommerce product CRUD operations for artists. Artists can create, read, update, and delete products linked to their profiles. Products are created on Blog ID 3 (shop.extrachill.com) and cross-referenced to artist profiles on Blog ID 4.

## Permission
- **GET** (List): Artist must exist on current blog
- **GET** (Single): Artist must own product or be admin
- **POST** (Create): Current user must have artist status
- **PUT** (Update): Current user must own product or be admin
- **DELETE** (Delete): Current user must own product or be admin

## GET List Request

```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/shop/products?page=1&per_page=20"
```

### Query Parameters
| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `page` | integer | 1 | Pagination page number |
| `per_page` | integer | 20 | Results per page (max 100) |

### GET List Response
```json
{
  "products": [
    {
      "id": 456,
      "name": "Album Name",
      "description": "Album description",
      "price": "9.99",
      "sale_price": "7.99",
      "regular_price": "9.99",
      "stock": 100,
      "stock_status": "instock",
      "image_id": 789,
      "image_url": "https://example.com/wp-content/uploads/album.jpg",
      "gallery_image_ids": [789, 790, 791],
      "artist_id": 123
    }
  ],
  "total": 5,
  "page": 1,
  "per_page": 20
}
```

## GET Single Product Request

```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/shop/products/456"
```

### GET Single Response
Same product object structure as in list response.

## POST Create Product Request

```json
{
  "name": "New Album",
  "description": "Album description text",
  "price": "12.99",
  "stock": 50,
  "artist_id": 123
}
```

### POST Response
Returns the created product object (same structure as GET).

## PUT Update Product Request

```json
{
  "name": "Updated Album Name",
  "description": "Updated description",
  "price": "14.99",
  "sale_price": "10.99",
  "stock": 75
}
```

### PUT Response
Returns the updated product object (same structure as GET).

## DELETE Product Request

```bash
curl -X DELETE "http://site.local/wp-json/extrachill/v1/shop/products/456"
```

### DELETE Response
```json
{
  "deleted": true,
  "id": 456,
  "message": "Product moved to trash"
}
```

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `missing_artist_status` | 403 | Current user is not an artist |
| `missing_permission` | 403 | User cannot manage this product |
| `invalid_product_id` | 404 | Product not found |
| `invalid_artist_id` | 400 | Artist ID is invalid |
| `validation_error` | 400 | Required fields missing (name, price, etc.) |
| `database_error` | 500 | Failed to save product |

## Implementation Notes
- Products are created on Blog ID 3 (shop.extrachill.com)
- Products are linked to artist profiles via `_artist_profile_id` post meta
- Images are managed via the `/media` endpoint with `product_image` context
- Gallery images support multiple images per product
- Stock tracking is enabled and required
- Sale prices are optional (when set, displayed alongside regular price)
- Products can have full descriptions, attributes, and related products (WooCommerce features)
- Trashed products can be permanently deleted via WordPress admin

## Product Fields

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | integer | N/A | Read-only, assigned on creation |
| `name` | string | Yes | Product title |
| `description` | string | No | Product description (HTML allowed) |
| `price` | string | Yes | Regular price |
| `sale_price` | string | No | Sale price (optional, triggers sale badge) |
| `regular_price` | string | N/A | Read-only, same as `price` |
| `stock` | integer | Yes | Quantity in stock |
| `stock_status` | string | N/A | Read-only ('instock', 'outofstock') |
| `image_id` | integer | No | Featured image attachment ID |
| `image_url` | string | N/A | Read-only, featured image URL |
| `gallery_image_ids` | array | No | Array of additional image attachment IDs |
| `artist_id` | integer | Yes | Artist profile ID (must own artist) |

## Related Endpoints
- `GET /artists/{id}` - Get artist profile owning products
- `POST /media` - Upload product images
- `GET/POST/DELETE /shop/stripe` - Manage Stripe Connect for payment processing

## Usage Examples

### List Artist Products
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/shop/products?page=1&per_page=20"
```

### Get Single Product
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/shop/products/456"
```

### Create New Product
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/shop/products" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "New Album Release",
    "description": "Debut album featuring 12 original tracks",
    "price": "12.99",
    "stock": 100,
    "artist_id": 123
  }'
```

### Update Product
```bash
curl -X PUT "http://site.local/wp-json/extrachill/v1/shop/products/456" \
  -H "Content-Type: application/json" \
  -d '{
    "price": "14.99",
    "sale_price": "9.99",
    "stock": 75
  }'
```

### Delete Product
```bash
curl -X DELETE "http://site.local/wp-json/extrachill/v1/shop/products/456"
```

## Data Flow
1. Artist creates product via this endpoint
2. Product is stored on shop blog with artist meta
3. Images are uploaded via `/media` endpoint
4. Stripe payment processing configured via `/shop/stripe` endpoints
5. Customers purchase via WooCommerce storefront
6. Orders are processed through Stripe Connect
