# Shop Products

Product CRUD for a seller's shop. A product belongs to exactly one seller (artist, venue or promoter). Products with no seller are platform products and are managed in wp-admin, not through these routes.

| Route | Ability |
|---|---|
| `GET /wp-json/extrachill/v1/shop/products` | `extrachill/shop-list-products` |
| `POST /wp-json/extrachill/v1/shop/products` | `extrachill/shop-create-product` |
| `GET /wp-json/extrachill/v1/shop/products/{id}` | `extrachill/shop-get-product` |
| `PUT /wp-json/extrachill/v1/shop/products/{id}` | `extrachill/shop-update-product` |
| `DELETE /wp-json/extrachill/v1/shop/products/{id}` | `extrachill/shop-delete-product` |

## Sellers and permission

Every route is a thin adapter over an `extrachill-shop` ability. The route maps the request to ability input, calls the ability's own permission callback, and returns the ability result. All seller access is decided in one place: `extrachill_shop_can_manage_seller( $user_id, $seller )`.

- Name the seller with `seller_type` (`artist` | `venue` | `promoter`) and `seller_id`. `artist_id` is not accepted.
- Network admins (`manage_options`) may manage any seller.
- Everyone else needs the `shop` feature for their tier (`ec_feature_available( 'shop' )`) and membership of the seller: artist membership through the Artist owner (`ec_can_manage_artist()`), venue/promoter finance authority through Events (`extrachill/get-commerce-seller` → `can_manage_finances`).
- Errors: `401 rest_not_logged_in`, `403 shop_unavailable` (feature gate), `403 cannot_manage_seller`, `400 missing_seller` / `invalid_seller`.

Item routes (`/products/{id}`) authorize against the product's own seller and refuse a product without one (`403 product_has_no_seller`, `404 product_not_found`). A `PUT` that names `seller_type` + `seller_id` moves the product to that seller, and the user must manage both.

## GET /shop/products

Parameters: `seller_type`, `seller_id` (required); `page`, `per_page` (default 20, max 100). Returns an array of products.

## POST /shop/products

Body: `seller_type`, `seller_id`, `name`, `price` (required); optional `sale_price`, `description`, `manage_stock`, `stock_quantity`, `image_id`, `gallery_ids`, `sizes` (`[{ "name": "M", "stock": 4 }]`), `ships_free`. Products are created as drafts.

## PUT /shop/products/{id}

Any create field, plus `status` (`draft` | `publish`) and `image_ids` (ordered; the first becomes the featured image). Publishing requires an image and a seller Stripe account that can receive payments.

## DELETE /shop/products/{id}

Trashes the product.

## Product shape

```json
{
  "id": 456,
  "name": "Tour Poster",
  "description": "<p>Screen printed.</p>",
  "price": "20.00",
  "sale_price": "",
  "manage_stock": true,
  "stock_quantity": 10,
  "status": "publish",
  "permalink": "https://shop.extrachill.com/product/tour-poster/",
  "seller": { "type": "venue", "id": 1524 },
  "image": { "id": 789, "url": "https://.../poster.jpg" },
  "gallery": [],
  "images": [ { "id": 789, "url": "https://.../poster.jpg" } ],
  "sizes": [],
  "ships_free": false
}
```
