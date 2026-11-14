# Shop Orders

One seller's view of shop orders: list, mark shipped, refund.

| Route | Ability |
|---|---|
| `GET /wp-json/extrachill/v1/shop/orders` | `extrachill/shop-list-orders` |
| `PUT /wp-json/extrachill/v1/shop/orders/{id}/status` | `extrachill/shop-update-order-status` |
| `POST /wp-json/extrachill/v1/shop/orders/{id}/refund` | `extrachill/shop-refund-order` |

## Sellers and permission

Every route is a thin adapter over an `extrachill-shop` ability. The route maps the request to ability input, calls the ability's own permission callback, and returns the ability result. All seller access is decided in one place: `extrachill_shop_can_manage_seller( $user_id, $seller )`.

- Name the seller with `seller_type` (`artist` | `venue` | `promoter`) and `seller_id`. `artist_id` is not accepted.
- Network admins (`manage_options`) may manage any seller.
- Everyone else needs the `shop` feature for their tier (`ec_feature_available( 'shop' )`) and membership of the seller: artist membership through the Artist owner (`ec_can_manage_artist()`), venue/promoter finance authority through Events (`extrachill/get-commerce-seller` → `can_manage_finances`).
- Errors: `401 rest_not_logged_in`, `403 shop_unavailable` (feature gate), `403 cannot_manage_seller`, `400 missing_seller` / `invalid_seller`.

## GET /shop/orders

Parameters: `seller_type`, `seller_id` (required); `status` (`all` default, `needs_fulfillment`, `completed`); `page` (default 1); `per_page` (default 20, max 100).

```bash
curl "https://shop.extrachill.com/wp-json/extrachill/v1/shop/orders?seller_type=venue&seller_id=1524&status=needs_fulfillment" \
  -H "X-WP-Nonce: $NONCE"
```

```json
{
  "orders": [
    {
      "id": 12345,
      "number": "12345",
      "status": "processing",
      "date_created": "2025-01-15T10:30:00+00:00",
      "customer": {
        "name": "Fan Name",
        "email": "fan@example.com",
        "address": { "address_1": "123 Main St", "address_2": "", "city": "Los Angeles", "state": "CA", "postcode": "90001", "country": "US" }
      },
      "items": [ { "product_id": 456, "name": "Tour Poster", "quantity": 1, "total": 14.99 } ],
      "seller_payout": 13.49,
      "order_total": 14.99,
      "tracking_number": "",
      "ships_free_only": false
    }
  ],
  "total": 42,
  "total_pages": 3,
  "page": 1,
  "per_page": 20,
  "needs_fulfillment_count": 5
}
```

Only the seller's own items and payout are returned. `artist_payout` was renamed `seller_payout`.

## PUT /shop/orders/{id}/status

Body: `seller_type`, `seller_id`, `status` (`completed`), optional `tracking_number`. Marks the seller's portion shipped and returns the updated order in the list shape.

## POST /shop/orders/{id}/refund

Body: `seller_type`, `seller_id`, optional `reason`. Refunds the seller's portion of the order and reverses that seller's transfer.

```json
{ "success": true, "order_id": 12345, "refund_amount": 14.99, "reversed": true }
```

Errors: `404 order_not_found` when the order has no items from the seller, plus the seller errors above.
