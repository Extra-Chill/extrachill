# Stripe Webhook Handler

Centralized endpoint for receiving and processing Stripe webhooks. Routes webhook events to the appropriate handler in the extrachill-shop plugin.

## Endpoints

### Stripe Webhook Receiver

**Endpoint**: `POST /wp-json/extrachill/v1/shop/stripe-webhook`

**Purpose**: Receive webhook notifications from Stripe for payment processing events.

**Permission**: Public (Stripe webhook signature verification required)

**Parameters**: None (webhook payload is in request body)

**Request Example**:
```
POST /wp-json/extrachill/v1/shop/stripe-webhook
Content-Type: application/json
Stripe-Signature: t=1234567890,v1=signature...

{
  "id": "evt_1234567890",
  "object": "event",
  "api_version": "2020-08-27",
  "created": 1234567890,
  "data": {
    "object": {
      "id": "pi_1234567890",
      "object": "payment_intent",
      "amount": 1000,
      "currency": "usd",
      "status": "succeeded"
    }
  },
  "livemode": false,
  "pending_webhooks": 1,
  "request": {
    "id": "req_1234567890",
    "idempotency_key": null
  },
  "type": "payment_intent.succeeded"
}
```

**Response** (HTTP 200):
```json
{
  "received": true,
  "event_type": "payment_intent.succeeded",
  "processed": true
}
```

**Error Responses**:
- `400` - Invalid webhook signature or malformed payload
- `500` - Webhook processing failed or handler unavailable

**Implementation Details**:
- Requires extrachill-shop plugin for webhook processing
- Calls `extrachill_shop_handle_webhook()` function
- Webhook signature verification handled by shop plugin
- Supports all Stripe webhook event types
- Returns success immediately after queuing for processing

**File**: `inc/routes/shop/stripe-webhook.php`

---

## Usage Examples

### Webhook Configuration

Stripe webhooks should be configured to point to this endpoint:

```
Webhook URL: https://yoursite.com/wp-json/extrachill/v1/shop/stripe-webhook
Events: Select all relevant payment and subscription events
```

### Event Processing

The endpoint automatically routes events to appropriate handlers:

```php
// In extrachill-shop plugin
function extrachill_shop_handle_webhook($request) {
  $payload = $request->get_json_params();

  switch ($payload['type']) {
    case 'payment_intent.succeeded':
      // Process successful payment
      break;
    case 'customer.subscription.created':
      // Handle new subscription
      break;
    case 'invoice.payment_failed':
      // Handle failed payment
      break;
  }

  return rest_ensure_response(['processed' => true]);
}
```

---

## Usage Notes

**Security**:
- Stripe webhook signatures are verified by the shop plugin
- Only accepts valid webhook events from Stripe
- No authentication required beyond signature verification

**Event Types**:
- Supports all Stripe webhook events
- Events are processed asynchronously
- Failed processing is logged for review

**Integration**:
- Requires extrachill-shop plugin
- Webhook URL must be publicly accessible
- HTTPS required for production Stripe webhooks

**Monitoring**:
- Webhook delivery status visible in Stripe dashboard
- Failed webhooks can be manually retried
- Processing errors logged for debugging

**Related Endpoints**:
- [Stripe Connect](stripe-connect.md) - Stripe account connection management</content>
<parameter name="filePath">docs/routes/shop/stripe-webhook.md