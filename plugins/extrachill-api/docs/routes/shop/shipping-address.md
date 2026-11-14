# Shipping Address

A seller's ship-from address, used to buy shipping labels.

| Route | Ability |
|---|---|
| `GET /wp-json/extrachill/v1/shop/shipping-address` | `extrachill/shop-get-shipping-address` |
| `PUT /wp-json/extrachill/v1/shop/shipping-address` | `extrachill/shop-update-shipping-address` |

## Sellers and permission

Every route is a thin adapter over an `extrachill-shop` ability. The route maps the request to ability input, calls the ability's own permission callback, and returns the ability result. All seller access is decided in one place: `extrachill_shop_can_manage_seller( $user_id, $seller )`.

- Name the seller with `seller_type` (`artist` | `venue` | `promoter`) and `seller_id`. `artist_id` is not accepted.
- Network admins (`manage_options`) may manage any seller.
- Everyone else needs the `shop` feature for their tier (`ec_feature_available( 'shop' )`) and membership of the seller: artist membership through the Artist owner (`ec_can_manage_artist()`), venue/promoter finance authority through Events (`extrachill/get-commerce-seller` → `can_manage_finances`).
- Errors: `401 rest_not_logged_in`, `403 shop_unavailable` (feature gate), `403 cannot_manage_seller`, `400 missing_seller` / `invalid_seller`.

## GET

Parameters: `seller_type`, `seller_id`.

```json
{
  "seller": { "type": "artist", "id": 123 },
  "address": { "name": "Band Name", "street1": "123 Main St", "street2": "", "city": "Austin", "state": "TX", "zip": "78701", "country": "US" },
  "is_set": true
}
```

## PUT

Body: `seller_type`, `seller_id`, `name`, `street1`, `city`, `state`, `zip` (required), optional `street2`. US addresses only.

```json
{ "success": true, "seller": { "type": "artist", "id": 123 }, "address": { "...": "..." } }
```
