# Stripe Connect Endpoint

## Route
`GET/POST/DELETE /wp-json/extrachill/v1/shop/stripe`
`POST /wp-json/extrachill/v1/shop/stripe/connect`
`DELETE /wp-json/extrachill/v1/shop/stripe/disconnect`
`POST /wp-json/extrachill/v1/shop/stripe/webhook`

## Purpose
Manage Stripe Connect authentication and payment processing for artist shops. Artists can connect their Stripe accounts, view connection status, and handle webhook events.

## Permission
- **GET**: Artist must exist on current blog
- **POST** (connect): Current user must have artist status
- **DELETE** (disconnect): Current user must own the connected account or be admin
- **POST** (webhook): Public (webhook signing validates authenticity)

## GET Status Request

```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/shop/stripe"
```

### GET Response
```json
{
  "connected": true,
  "account_id": "acct_1234567890",
  "email": "artist@example.com",
  "charges_enabled": true,
  "verification_status": "verified"
}
```

## POST Connect Request

```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/shop/stripe/connect" \
  -H "Content-Type: application/json" \
  -d '{
    "artist_id": 123,
    "code": "authorization_code_from_stripe"
  }'
```

### Request Parameters
| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `artist_id` | integer | Yes | Artist profile ID (must own or be admin) |
| `code` | string | Yes | OAuth authorization code from Stripe |

### POST Response
```json
{
  "success": true,
  "connected": true,
  "account_id": "acct_1234567890",
  "message": "Stripe account connected successfully"
}
```

## DELETE Disconnect Request

```bash
curl -X DELETE "http://site.local/wp-json/extrachill/v1/shop/stripe/disconnect" \
  -H "Content-Type: application/json" \
  -d '{
    "artist_id": 123
  }'
```

### Request Parameters
| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `artist_id` | integer | Yes | Artist profile ID |

### DELETE Response
```json
{
  "success": true,
  "disconnected": true,
  "message": "Stripe account disconnected"
}
```

## POST Webhook Request

```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/shop/stripe/webhook" \
  -H "Stripe-Signature: sig_..." \
  -d @stripe_event.json
```

### Webhook Headers
| Header | Required | Notes |
| --- | --- | --- |
| `Stripe-Signature` | Yes | HMAC-SHA256 signature for event verification |

### Supported Events
- `charge.succeeded` - Payment processed successfully
- `charge.failed` - Payment failed
- `charge.refunded` - Payment refunded
- `account.updated` - Stripe account details changed
- `account.external_account.created` - Bank account added
- `account.external_account.deleted` - Bank account removed

### Webhook Response
```json
{
  "received": true,
  "event_id": "evt_1234567890"
}
```

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `missing_artist_status` | 403 | Current user is not an artist |
| `missing_permission` | 403 | User cannot manage this Stripe account |
| `invalid_artist_id` | 404 | Artist not found |
| `stripe_error` | 400 | Stripe API error |
| `invalid_code` | 400 | Authorization code is invalid or expired |
| `webhook_signature_invalid` | 401 | Webhook signature verification failed |
| `webhook_processing_error` | 500 | Failed to process webhook event |

## Implementation Notes
- Stripe Connect uses OAuth 2.0 flow for artist authorization
- Account credentials are stored securely on the artist profile
- Webhooks are signed and verified using Stripe's HMAC-SHA256 signature
- Each artist can maintain only one Stripe connection
- Disconnecting removes access to Stripe features but preserves payment history
- Webhook events are processed asynchronously to prevent timeout issues
- Failed webhook deliveries are automatically retried by Stripe

## Connection Flow
1. Artist clicks "Connect Stripe Account" button
2. Redirected to Stripe authorization page
3. After authorization, Stripe redirects back with OAuth code
4. Frontend calls `POST /shop/stripe/connect` with code
5. Backend exchanges code for access token and account ID
6. Account details stored on artist profile
7. Artist can now process payments

## Related Endpoints
- `GET/POST/PUT/DELETE /shop/products` - Manage products for sale
- `GET /artists/{id}` - Get artist profile details

## Usage Examples

### Check Stripe Connection Status
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/shop/stripe"
```

### Connect Stripe Account (after OAuth flow)
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/shop/stripe/connect" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: nonce_value" \
  -d '{
    "artist_id": 123,
    "code": "ac_1234567890ABCDEF"
  }'
```

### Disconnect Stripe Account
```bash
curl -X DELETE "http://site.local/wp-json/extrachill/v1/shop/stripe/disconnect" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: nonce_value" \
  -d '{
    "artist_id": 123
  }'
```

## Webhook Integration
Stripe automatically sends webhook events to your configured endpoint. The plugin handles:
1. Signature verification using Stripe's public key
2. Event processing and logging
3. Retry handling for failed events
4. Duplicate event detection

## Payment Processing
Once connected, products can be purchased through Stripe:
1. Customer adds product to cart
2. Checkout triggers Stripe payment form
3. Customer enters payment details
4. Payment processed through Stripe Connect
5. Webhook event confirms payment
6. Order fulfillment triggered

## Security
- OAuth authorization tokens are encrypted in database
- Webhook events are cryptographically signed by Stripe
- API calls use HTTPS only
- Sensitive data is never logged
