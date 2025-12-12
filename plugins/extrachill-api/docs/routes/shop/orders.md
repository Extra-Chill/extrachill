# Shop Orders & Earnings

Endpoints for managing orders and earnings for artist products in the WooCommerce shop.

## Endpoints

### List Artist Orders

**Endpoint**: `GET /wp-json/extrachill/v1/shop/orders`

**Purpose**: List orders containing products from artists the user manages, with filtering and pagination.

**Parameters**:
- `limit` (int, optional) - Number of orders to return (default: 50)
- `status` (array, optional) - Order statuses to include (default: ['completed', 'processing', 'on-hold', 'pending'])

**Response** (HTTP 200):
```json
[
  {
    "order_id": 123,
    "order_number": "WC-123",
    "status": "completed",
    "date_created": "2025-01-15T10:30:00+00:00",
    "items": [
      {
        "product_id": 456,
        "name": "Album Name",
        "quantity": 1,
        "line_total": 9.99,
        "artist_payout": 8.99
      }
    ],
    "artist_total": 8.99,
    "payout_status": "eligible"
  }
]
```

**Permission**: User must be logged in and have artist status

**File**: `inc/routes/shop/orders.php`

### Get Earnings Summary

**Endpoint**: `GET /wp-json/extrachill/v1/shop/earnings`

**Purpose**: Get earnings summary statistics for all products from artists the user manages.

**Response** (HTTP 200):
```json
{
  "total_orders": 25,
  "total_earnings": 249.75,
  "pending_payout": 49.95,
  "completed_sales": 20
}
```

**Permission**: User must be logged in and have artist status

**File**: `inc/routes/shop/orders.php`

## Processing Logic

### Order Listing
1. Validates user has artist status and can manage products
2. Switches to shop blog context for WooCommerce operations
3. Queries orders containing products linked to user's artists
4. Calculates artist payouts based on configurable commission rates
5. Returns formatted order data with payout information

### Earnings Summary
1. Aggregates order data across all user's artist products
2. Calculates total earnings, pending payouts, and completed sales
3. Provides real-time financial overview for artist shop management

## Dependencies

- WooCommerce plugin active on shop blog
- Artist ownership validation functions
- Commission rate configuration (defaults to 10% platform fee)

## Usage Examples

### Get Recent Orders

```javascript
fetch('/wp-json/extrachill/v1/shop/orders?limit=10&status=completed', {
  headers: {
    'X-WP-Nonce': wpApiSettings.nonce
  }
})
.then(response => response.json())
.then(orders => {
  // Display orders with payout information
});
```

### Display Earnings Dashboard

```javascript
fetch('/wp-json/extrachill/v1/shop/earnings', {
  headers: {
    'X-WP-Nonce': wpApiSettings.nonce
  }
})
.then(response => response.json())
.then(earnings => {
  console.log(`Total earnings: $${earnings.total_earnings}`);
});
```

---

## Notes

- Orders are filtered to only show those containing products from artists the user manages
- Payout calculations respect configurable commission rates
- Earnings summary provides aggregated financial data for dashboard display
- All operations require proper artist permissions and WooCommerce integration