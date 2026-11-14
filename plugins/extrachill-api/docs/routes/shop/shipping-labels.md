# Shipping Labels

Buy and look up USPS labels (via Shippo) for a seller's portion of an order. The ability owns the Shippo call, the order writes, the status change and the email.

| Route | Ability |
|---|---|
| `POST /wp-json/extrachill/v1/shop/shipping-labels` | `extrachill/shop-create-shipping-label` |
| `GET /wp-json/extrachill/v1/shop/shipping-labels/{order_id}` | `extrachill/shop-get-shipping-label` |

## Sellers and permission

Every route is a thin adapter over an `extrachill-shop` ability. The route maps the request to ability input, calls the ability's own permission callback, and returns the ability result. All seller access is decided in one place: `extrachill_shop_can_manage_seller( $user_id, $seller )`.

- Name the seller with `seller_type` (`artist` | `venue` | `promoter`) and `seller_id`. `artist_id` is not accepted.
- Network admins (`manage_options`) may manage any seller.
- Everyone else needs the `shop` feature for their tier (`ec_feature_available( 'shop' )`) and membership of the seller: artist membership through the Artist owner (`ec_can_manage_artist()`), venue/promoter finance authority through Events (`extrachill/get-commerce-seller` → `can_manage_finances`).
- Errors: `401 rest_not_logged_in`, `403 shop_unavailable` (feature gate), `403 cannot_manage_seller`, `400 missing_seller` / `invalid_seller`.

## POST

Body: `order_id`, `seller_type`, `seller_id`. Requires a saved ship-from address and an order containing the seller's items. A portion that only contains ships-free items is refused (`400 ships_free_order`); an order without the seller's items returns `400 invalid_seller`. If the seller already has a label for the order, that label is returned with `reprint: true` and no new purchase is made.

```json
{
  "success": true,
  "order_id": 12345,
  "seller": { "type": "venue", "id": 1524 },
  "label_url": "https://shippo-delivery.s3.amazonaws.com/label.pdf",
  "tracking_number": "9400111899223456789012",
  "tracking_url": "https://tools.usps.com/...",
  "carrier": "USPS",
  "service": "Priority Mail",
  "cost": 7.5
}
```

## GET /{order_id}

Parameters: `seller_type`, `seller_id`.

```json
{
  "order_id": 12345,
  "seller": { "type": "venue", "id": 1524 },
  "has_label": true,
  "label_url": "https://...",
  "tracking_number": "9400...",
  "carrier": "USPS",
  "service": "Priority Mail",
  "cost": 7.5,
  "purchased_at": "2025-01-15 10:30:00"
}
```

The ability `extrachill/shop-list-shipping-labels` (seller input) lists every label across the seller's orders; it has no `extrachill/v1` route.
