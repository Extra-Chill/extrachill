# Shop Orders

Manage artist shop orders, track fulfillment, and issue refunds for sold products.

## Endpoints

### List Artist Orders

**Endpoint**: `GET /wp-json/extrachill/v1/shop/orders`

**Purpose**: Retrieve paginated list of orders containing products from a specific artist.

**Permission**: Requires logged-in user who can manage the artist

**Parameters**:
- `artist_id` (integer, required) - The artist profile ID
- `status` (string, optional) - Filter by status: `all` (default), `needs_fulfillment`, or `completed`
- `page` (integer, optional) - Page number (default: 1)
- `per_page` (integer, optional) - Results per page (default: 20, max: 100)

**Response** (HTTP 200):
```json
{
  "orders": [
    {
      "id": 12345,
      "number": "#12345",
      "status": "processing",
      "date_created": "2025-01-15T10:30:00Z",
      "customer": {
        "name": "Fan Name",
        "email": "fan@example.com",
        "address": {
          "address_1": "123 Main St",
          "address_2": "",
          "city": "Los Angeles",
          "state": "CA",
          "postcode": "90001",
          "country": "US"
        }
      },
      "items": [
        {
          "product_id": 456,
          "name": "Album CD",
          "quantity": 1,
          "total": 14.99
        }
      ],
      "artist_payout": 7.50,
      "order_total": 14.99,
      "tracking_number": ""
    }
  ],
  "total": 42,
  "total_pages": 3,
  "page": 1,
  "per_page": 20,
  "needs_fulfillment_count": 5
}
```

**Error Responses**:
- `400` - Missing artist_id
- `401` - User not logged in
- `403` - User cannot manage the artist
- `500` - Shop site not configured or WooCommerce unavailable

### Update Order Status

**Endpoint**: `PUT /wp-json/extrachill/v1/shop/orders/{id}/status`

**Purpose**: Mark an order as shipped or completed.

**Permission**: Requires logged-in user who can manage the artist

**Parameters**:
- `id` (integer, required, in URL) - Order ID
- `artist_id` (integer, required) - The artist profile ID
- `status` (string, required) - Status value: `completed` (marks order as shipped)
- `tracking_number` (string, optional) - Shipping tracking number

**Request Example**:
```json
{
  "artist_id": 123,
  "status": "completed",
  "tracking_number": "1Z999AA10123456784"
}
```

**Response** (HTTP 200):
```json
{
  "id": 12345,
  "number": "#12345",
  "status": "completed",
  "date_created": "2025-01-15T10:30:00Z",
  "customer": { /* ... */ },
  "items": [ /* ... */ ],
  "artist_payout": 7.50,
  "order_total": 14.99,
  "tracking_number": "1Z999AA10123456784"
}
```

**Error Responses**:
- `400` - Invalid parameters or invalid artist
- `401` - User not logged in
- `403` - User cannot manage the artist or order doesn't contain artist's products
- `404` - Order not found
- `500` - WooCommerce unavailable

### Issue Refund

**Endpoint**: `POST /wp-json/extrachill/v1/shop/orders/{id}/refund`

**Purpose**: Issue a full refund for the artist's portion of an order via Stripe.

**Permission**: Requires logged-in user who can manage the artist

**Parameters**:
- `id` (integer, required, in URL) - Order ID
- `artist_id` (integer, required) - The artist profile ID

**Request Example**:
```json
{
  "artist_id": 123
}
```

**Response** (HTTP 200):
```json
{
  "success": true,
  "order_id": 12345,
  "refund_amount": 7.50
}
```

**Error Responses**:
- `400` - Invalid parameters or invalid refund amount
- `401` - User not logged in
- `403` - User cannot manage the artist
- `404` - Order not found
- `500` - WooCommerce, Stripe, or payment intent service unavailable

**Implementation Details**:
- Orders stored on shop site (Blog ID 3)
- Each order contains `_artist_payouts` meta with per-artist totals
- Refunds processed via Stripe using stored payment intent ID
- Order status changes to "refunded" after successful refund
- Tracks artist-specific shipping info via `_artist_tracking_{artist_id}` meta

**File**: `inc/routes/shop/orders.php`

---

## Usage Examples

### Get Pending Orders (JavaScript)

```javascript
async function getPendingOrders(artistId) {
  const response = await fetch(
    `/wp-json/extrachill/v1/shop/orders?artist_id=${artistId}&status=needs_fulfillment`,
    {
      headers: {
        'Authorization': 'Bearer ' + localStorage.getItem('access_token')
      }
    }
  );

  const data = await response.json();
  
  console.log(`${data.needs_fulfillment_count} orders need fulfillment`);
  return data.orders;
}
```

### Mark Order Shipped

```javascript
async function shipOrder(orderId, artistId, trackingNumber) {
  const response = await fetch(
    `/wp-json/extrachill/v1/shop/orders/${orderId}/status`,
    {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + localStorage.getItem('access_token')
      },
      body: JSON.stringify({
        artist_id: artistId,
        status: 'completed',
        tracking_number: trackingNumber
      })
    }
  );

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message);
  }

  const order = await response.json();
  console.log(`Order ${order.number} marked as shipped`);
  return order;
}
```

### Issue Refund

```javascript
async function refundOrder(orderId, artistId) {
  const response = await fetch(
    `/wp-json/extrachill/v1/shop/orders/${orderId}/refund`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + localStorage.getItem('access_token')
      },
      body: JSON.stringify({
        artist_id: artistId
      })
    }
  );

  if (!response.ok) {
    const error = await response.json();
    throw new Error(`Refund failed: ${error.message}`);
  }

  const result = await response.json();
  console.log(`Refund issued: $${result.refund_amount}`);
  return result;
}
```

### Order Fulfillment Dashboard

```javascript
async function loadOrderDashboard(artistId) {
  // Get all orders
  const response = await fetch(
    `/wp-json/extrachill/v1/shop/orders?artist_id=${artistId}&per_page=50`,
    {
      headers: {
        'Authorization': 'Bearer ' + localStorage.getItem('access_token')
      }
    }
  );

  const data = await response.json();

  // Display pending orders
  const pending = data.orders.filter(o => 
    o.status === 'processing' || o.status === 'on-hold'
  );

  return {
    total_orders: data.total,
    pending_count: data.needs_fulfillment_count,
    pending_orders: pending
  };
}
```

---

## Usage Notes

**Order Status Values**:
- `processing` - Awaiting shipment (needs fulfillment)
- `on-hold` - On hold, needs fulfillment
- `completed` - Marked as shipped by artist
- `refunded` - Full refund issued
- `failed` - Payment failed

**Fulfillment Workflow**:
1. Artist receives notification of new order
2. Call GET endpoint to view pending orders
3. Ship order and get tracking number
4. Call PUT endpoint to mark as completed with tracking
5. Customer receives shipping notification

**Refunds**:
- Only full refunds supported (for artist's portion)
- Requires valid Stripe payment intent
- Automatically changes order status to "refunded"
- Cannot be undone via API (must be handled in Stripe dashboard)

**Artist Payout**:
- `artist_payout` field shows artist's earnings from order
- Calculated based on product prices and commission structure
- May be less than `order_total` if platform takes commission

**Related Endpoints**:
- [Shop Products](products.md) - Manage product listings
- [Stripe Connect](stripe-connect.md) - Configure payment processing
- [User Artists](../users/artists.md) - Manage artist profiles
