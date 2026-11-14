# E-commerce Integration

The Extra Chill Platform implements a complex marketplace architecture integrating WooCommerce, Stripe Connect, and Shippo across multiple sites.

## Core Architectural Patterns

### Cross-Site Integration
- **shop.extrachill.com**: Primary e-commerce surface (WooCommerce, product catalog)
- **artist.extrachill.com**: Artist management surface (Shop Manager block)
- **extrachill.link**: Domain-mapped frontend for artist link pages
- **extrachill-api**: Centralized REST API for all cross-site operations

## Stripe Connect Payment Flow

The platform uses Stripe Connect Express. Every seller (artist, venue, or promoter) has its own connected account and is the merchant of record for its sales.

### 1. Seller Onboarding
- Sellers start onboarding from the **Shop Manager** (`ec_get_shop_manager_url()`)
- The system creates a Stripe Express account for the seller reference (`type:id`)
- The account ID and status are stored by the shop (blog 3) in the `extrachill_shop_stripe_seller_<type>:<id>` option, with a hashed reverse index (`extrachill_shop_stripe_account_<sha256>`) that `account.updated` webhooks use to find the seller
- **Status Tiers**: `pending`, `active`, `restricted`
- **Capability Requirements**: `charges_enabled` and `payouts_enabled` must be true before the seller can take payments

### 2. Payment Processing
- A cart holds products from exactly one seller (enforced on add-to-cart and at checkout)
- Checkout creates a Stripe Checkout Session as a **direct charge** on the seller's connected account (`stripe_account`), with Extra Chill's commission as the `application_fee_amount`
- The money settles on the seller's account and Stripe moves the application fee to the platform. No transfers are made after payment.
- Platform products (no payout seller) are charged on the platform account with no application fee
- WooCommerce tax calculation is off. The seller, as merchant of record, is responsible for any sales tax.

### 3. API Endpoints
- `GET /shop/stripe-connect/status`: Checks account status and capabilities
- `POST /shop/stripe-connect/onboarding-link`: Generates URL for Stripe Express setup
- `POST /shop/stripe-connect/dashboard-link`: Generates URL for artist's Stripe dashboard

## Shipping Label System (Shippo)

Automated shipping fulfillment using Shippo API for domestic USPS shipments.

### 1. Artist Shipping Settings
- Sellers must configure their ship-from address in the **Shop Manager**
- The address is stored by the shop in the `extrachill_shop_shipping_seller_<type>:<id>` option
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
