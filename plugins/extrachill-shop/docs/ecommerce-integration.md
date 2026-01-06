# E-commerce Integration

The Extra Chill Platform implements a complex marketplace architecture integrating WooCommerce, Stripe Connect, and Shippo across multiple sites.

## Core Architectural Patterns

### Cross-Site Integration
- **shop.extrachill.com**: Primary e-commerce surface (WooCommerce, product catalog)
- **artist.extrachill.com**: Artist management surface (Shop Manager block)
- **extrachill.link**: Domain-mapped frontend for artist link pages
- **extrachill-api**: Centralized REST API for all cross-site operations

## Stripe Connect Payout Flow (v0.5.0)

The platform uses Stripe Connect Express to handle artist payouts for marketplace sales.

### 1. Artist Onboarding
- Artists initiate onboarding via the **Shop Manager** on `artist.extrachill.com`
- The system creates a Stripe Express account linked to the artist profile
- Account IDs and status are stored on the `artist_profile` post (Blog ID 4)
- **Status Tiers**: `pending`, `active`, `restricted`
- **Capability Requirements**: `charges_enabled` and `payouts_enabled` must be true for product publishing

### 2. Payment Processing
- Uses Stripe's **Separate Charges and Transfers** pattern
- Payments are collected by the platform on `shop.extrachill.com`
- Upon payment completion (`woocommerce_payment_complete`), the system calculates the artist portion
- Transfers are initiated from the platform account to the connected artist account via Stripe's "Separate Charges and Transfers" payout pattern, ensuring funds are distributed only after successful platform collection.

### 3. API Endpoints
- `GET /shop/stripe-connect/status`: Checks account status and capabilities
- `POST /shop/stripe-connect/onboarding-link`: Generates URL for Stripe Express setup
- `POST /shop/stripe-connect/dashboard-link`: Generates URL for artist's Stripe dashboard

## Shipping Label System (Shippo)

Automated shipping fulfillment using Shippo API for domestic USPS shipments.

### 1. Artist Shipping Settings
- Artists must configure their shipping address in the **Shop Manager**
- Address is stored as meta on the `artist_profile` post
- Fixed domestic flat rate of **$5.00** applied at checkout, except for orders containing only "Ships Free" items.
- "Ships Free" flag on products allows small items (stickers, patches) to bypass the $5 flat-rate shipping when they are the only items in the artist portion of the cart.

### 2. Label Fulfillment Flow
- When an order is "Processing", artists can purchase labels in the **Shop Manager**
- System fetches the artist's address (From) and customer's address (To)
- Automatically selects the **cheapest USPS rate** available via Shippo integration ($5 flat-rate label model)
- **Ships Free Exception**: If an order consists only of "Ships Free" items, the system blocks platform label purchases. Artists must ship these manually (e.g., via stamped envelope) and mark the order as shipped.
- Purchases label and retrieves tracking number + PDF URL
- Updates WooCommerce order status to "Completed" and adds tracking info

### 3. API Endpoints
- `GET/PUT /shop/shipping-address`: Manage artist fulfillment address
- `GET /shop/shipping-labels/{order_id}`: Retrieve existing label details
- `POST /shop/shipping-labels`: Purchase new label for an order

## Lifetime Extra Chill Membership System

- **SKU**: `ec-lifetime-membership`
- **Product**: Managed automatically on the shop site
- **Validation**: Cross-site membership status via `is_user_lifetime_member()` in `extrachill-users` (provides ad-free benefit)
- **Storage**: User meta `extrachill_lifetime_membership`

## Security & Verification
- **Capability Checks**: All artist shop operations require `ec_can_manage_artist()`
- **Nonce Protection**: All REST API calls require valid WordPress nonces
- **Data Integrity**: Uses `switch_to_blog()` with try/finally blocks for reliable multisite data access
