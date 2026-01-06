# Shipping Labels Endpoints

Purchase and retrieve shipping labels via Shippo integration.

## GET /wp-json/extrachill/v1/shop/shipping-labels/{order_id}

Get existing shipping label for an order.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `order_id` | integer | Yes | WooCommerce order ID (URL param) |
| `artist_id` | integer | Yes | Artist profile ID (query param) |

### Response

```json
{
    "order_id": 456,
    "artist_id": 123,
    "has_label": true,
    "label_url": "https://shippo.com/label.pdf",
    "tracking_number": "9400111899223...",
    "carrier": "USPS",
    "service": "Priority Mail",
    "cost": 7.85
}
```

## POST /wp-json/extrachill/v1/shop/shipping-labels

Purchase a new shipping label for an order.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `order_id` | integer | Yes | WooCommerce order ID |
| `artist_id` | integer | Yes | Artist profile ID |

### Response

```json
{
    "success": true,
    "order_id": 456,
    "artist_id": 123,
    "label_url": "https://shippo.com/label.pdf",
    "tracking_number": "9400111899223...",
    "tracking_url": "https://tools.usps.com/go/TrackConfirmAction?tLabels=...",
    "carrier": "USPS",
    "service": "Priority Mail",
    "cost": 7.85
}
```

### Reprint Response

If a label already exists for the order, it returns the existing label:

```json
{
    "success": true,
    "reprint": true,
    "order_id": 456,
    "artist_id": 123,
    "label_url": "https://shippo.com/label.pdf",
    "tracking_number": "9400111899223...",
    "carrier": "USPS",
    "service": "Priority Mail",
    "cost": 7.85
}
```

## Features

- Automatically selects cheapest USPS rate for domestic shipments
- Flat-rate shipping ($5.00) configured at platform level
- Bypasses label purchase for "Ships Free" orders (returns `ships_free_order` error)
- Updates order status to "completed" upon label purchase
- Syncs tracking number to WooCommerce order metadata (`_tracking_number`)
- Supports label reprints (returns existing label if already purchased)
- US domestic shipping only (international returns error)

## Requirements

- Artist must have shipping address configured
- Shippo API must be configured
- Order must contain products from the specified artist

## Authentication

Requires authenticated user with artist management permissions.

## Error Responses

### 400 No Shipping Address

```json
{
    "code": "no_shipping_address",
    "message": "Please set up your shipping address in the Settings tab before printing labels.",
    "data": { "status": 400 }
}
```

### 400 Invalid Artist

```json
{
    "code": "invalid_artist",
    "message": "This order does not contain products from your artist.",
    "data": { "status": 400 }
}
```

### 400 International Not Supported

```json
{
    "code": "international_not_supported",
    "message": "International shipping is not currently supported.",
    "data": { "status": 400 }
}
```

### 400 Ships Free Order

```json
{
    "code": "ships_free_order",
    "message": "This order only contains items that ship for free. No shipping label is required.",
    "data": { "status": 400 }
}
```

### 404 Order Not Found

```json
{
    "code": "order_not_found",
    "message": "Order not found.",
    "data": { "status": 404 }
}
```

### 500 Shippo Not Configured

```json
{
    "code": "shippo_not_configured",
    "message": "Shipping service is not configured. Please contact support.",
    "data": { "status": 500 }
}
```

## Related Endpoints

- [GET/PUT /shop/shipping-address](shipping-address.md) - Manage artist shipping address
- [GET /shop/orders](orders.md) - Artist order management
