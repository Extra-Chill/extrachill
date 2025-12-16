# Stripe Connect

Stripe Connect is scoped to an `artist_profile` post (artist site). These endpoints support checking account status and generating onboarding/dashboard links.

## Routes
- `GET /wp-json/extrachill/v1/shop/stripe-connect/status?artist_id=123`
- `POST /wp-json/extrachill/v1/shop/stripe-connect/onboarding-link`
- `POST /wp-json/extrachill/v1/shop/stripe-connect/dashboard-link`

## Permission
- User must be logged in.
- `artist_id` is required.
- User must be able to manage the artist: `ec_can_manage_artist( get_current_user_id(), artist_id )`.

## GET status

### Request
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/shop/stripe-connect/status?artist_id=123" \
  -H "X-WP-Nonce: nonce_value"
```

### Response
```json
{
  "connected": true,
  "account_id": "acct_123",
  "status": "active",
  "can_receive_payments": true,
  "charges_enabled": true,
  "payouts_enabled": true,
  "details_submitted": true
}
```

If the artist has no connected account:
```json
{
  "connected": false,
  "account_id": null,
  "status": null,
  "can_receive_payments": false
}
```

## POST onboarding link
Creates the Stripe Express account if needed (artist-scoped) and returns an onboarding link.

### Request
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/shop/stripe-connect/onboarding-link" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: nonce_value" \
  -d '{
    "artist_id": 123
  }'
```

### Response
```json
{
  "success": true,
  "url": "https://connect.stripe.com/setup/s/..."
}
```

## POST dashboard link
Returns a login link to the Stripe Express dashboard for the connected account.

### Request
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/shop/stripe-connect/dashboard-link" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: nonce_value" \
  -d '{
    "artist_id": 123
  }'
```

### Response
```json
{
  "success": true,
  "url": "https://connect.stripe.com/express_login/..."
}
```

## Notes
- Account creation uses the artist owner's email (`artist_profile.post_author`).
- Account data is stored on the `artist_profile` post meta, not user meta:
  - `_stripe_connect_account_id`
  - `_stripe_connect_status`
  - `_stripe_connect_onboarding_complete`
