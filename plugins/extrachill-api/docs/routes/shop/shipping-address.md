# Shipping Address Endpoints

Manage artist shipping from-address for order fulfillment.

## GET /wp-json/extrachill/v1/shop/shipping-address

Get artist's configured shipping address.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `artist_id` | integer | Yes | Artist profile ID |

### Response

```json
{
    "artist_id": 123,
    "address": {
        "name": "Artist Name",
        "street1": "123 Main St",
        "street2": "",
        "city": "Austin",
        "state": "TX",
        "zip": "78701",
        "country": "US"
    },
    "is_set": true
}
```

## PUT /wp-json/extrachill/v1/shop/shipping-address

Update artist's shipping address.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `artist_id` | integer | Yes | Artist profile ID |
| `name` | string | Yes | Sender name |
| `street1` | string | Yes | Street address line 1 |
| `street2` | string | No | Street address line 2 |
| `city` | string | Yes | City |
| `state` | string | Yes | State (2-letter code) |
| `zip` | string | Yes | ZIP code |
| `country` | string | No | Country code (default: US) |

### Response

```json
{
    "success": true,
    "artist_id": 123,
    "address": {
        "name": "Artist Name",
        "street1": "123 Main St",
        "street2": "",
        "city": "Austin",
        "state": "TX",
        "zip": "78701",
        "country": "US"
    }
}
```

## Authentication

Requires authenticated user with artist management permissions.

## Error Responses

### 401 Unauthorized

```json
{
    "code": "rest_forbidden",
    "message": "You must be logged in.",
    "data": { "status": 401 }
}
```

### 403 Forbidden

```json
{
    "code": "rest_forbidden",
    "message": "You do not have permission to manage this artist.",
    "data": { "status": 403 }
}
```

### 500 Save Failed

```json
{
    "code": "save_failed",
    "message": "Failed to save shipping address.",
    "data": { "status": 500 }
}
```

## Related Endpoints

- [POST /shop/shipping-labels](shipping-labels.md) - Purchase shipping labels
- [GET /shop/orders](orders.md) - Artist order management
