# Stripe Connect

Stripe Connect accounts belong to a seller: an artist, venue or promoter. One account receives the seller's merch and ticket payouts.

| Route | Ability |
|---|---|
| `GET /wp-json/extrachill/v1/shop/stripe-connect/status` | `extrachill/shop-stripe-status` |
| `POST /wp-json/extrachill/v1/shop/stripe-connect/onboarding-link` | `extrachill/shop-stripe-onboarding-link` |
| `POST /wp-json/extrachill/v1/shop/stripe-connect/dashboard-link` | `extrachill/shop-stripe-dashboard-link` |

## Sellers and permission

Every route is a thin adapter over an `extrachill-shop` ability. The route maps the request to ability input, calls the ability's own permission callback, and returns the ability result. All seller access is decided in one place: `extrachill_shop_can_manage_seller( $user_id, $seller )`.

- Name the seller with `seller_type` (`artist` | `venue` | `promoter`) and `seller_id`. `artist_id` is not accepted.
- Network admins (`manage_options`) may manage any seller.
- Everyone else needs the `shop` feature for their tier (`ec_feature_available( 'shop' )`) and membership of the seller: artist membership through the Artist owner (`ec_can_manage_artist()`), venue/promoter finance authority through Events (`extrachill/get-commerce-seller` → `can_manage_finances`).
- Errors: `401 rest_not_logged_in`, `403 shop_unavailable` (feature gate), `403 cannot_manage_seller`, `400 missing_seller` / `invalid_seller`.

Events calls `GET status` (as the acting user) to check whether a venue or promoter can sell tickets.

## GET status

```bash
curl "https://shop.extrachill.com/wp-json/extrachill/v1/shop/stripe-connect/status?seller_type=promoter&seller_id=88" \
  -H "X-WP-Nonce: $NONCE"
```

```json
{
  "connected": true,
  "status": "active",
  "can_receive_payments": true,
  "charges_enabled": true,
  "payouts_enabled": true,
  "details_submitted": true
}
```

The raw Stripe account ID is never returned.

## POST onboarding-link

Body: `seller_type`, `seller_id`. Creates the Express account when needed and returns `{ "success": true, "url": "https://connect.stripe.com/..." }`. Venue and promoter onboarding returns to the seller's Events profile.

## POST dashboard-link

Body: `seller_type`, `seller_id`. Returns `{ "success": true, "url": "https://connect.stripe.com/express/..." }`. `404` when the seller has no Stripe account.
