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
- Checkout creates a Stripe Checkout Session as a **direct charge** on the seller's connected account (`stripe_account`), with Extra Chill's merch fee (commission, processing when the platform pays it, and the shipping hold; see §4) as the `application_fee_amount`
- The money settles on the seller's account and Stripe moves the application fee to the platform. No transfers are made after payment.
- Platform products (no payout seller) are charged on the platform account with no application fee
- Who pays card processing on a direct charge is the connected account's `controller.fees.payer`, stored as `fee_payer` on the seller's Stripe account record (see [tickets.md](tickets.md#where-the-payer-is-stored)). Express accounts, which the shop creates, report `application_express`: Stripe debits processing from **Extra Chill's** balance. Standard accounts report `account`: the seller pays it
- WooCommerce tax calculation is off. The seller, as merchant of record, is responsible for any sales tax.

### 3. API Endpoints
- `GET /shop/stripe-connect/status`: Checks account status and capabilities
- `POST /shop/stripe-connect/onboarding-link`: Generates URL for Stripe Express setup
- `POST /shop/stripe-connect/dashboard-link`: Generates URL for artist's Stripe dashboard

### 4. Merch Fee: Sellers Pay Card Processing (#1563)

Merch sellers pay card processing (Seller Agreement §3). `extrachill_commerce_merch_fee()` (`inc/core/commerce-fees.php`) sets the merch `application_fee_amount` in `extrachill_shop_start_merch_checkout()` (`checkout-handler.php`) from three parts:

- **Commission:** `merch_percent` (10%) of the item subtotal, after discounts, before tax and shipping. Same as `extrachill_commerce_fee( 'extrachill_merch', … )`.
- **Processing:** only when the platform pays processing (`fee_payer` starts with `application`, as on the Express accounts the shop creates). The estimate on the **whole charge** (items, shipping and order fees): `ceil( charge × processing_percent ) + processing_fixed_minor`, the same estimate and rounding tickets use (`extrachill_commerce_processing_estimate()`). When the account pays (`account`), it is 0: the processor already takes processing from the seller.
- **Shipping hold:** the buyer's shipping, held to buy the label (#1460, `shipping-recovery.php`). Unchanged.

`application_fee = min( charge, commission + processing + hold )`. If the cap bites (a charge of a few cents), commission gives way first, then processing; the hold always fits.

**Unknown payer = account pays (commission only).** Taking the estimate when the account also pays would charge the seller processing twice. If the account is really Express, Extra Chill pays processing out of its 10% on that sale, as before #1563. Merch checkout calls `extrachill_shop_get_seller_fee_payer()`, which backfills an unknown payer from Stripe once and caches a failed lookup for `EXTRACHILL_SHOP_STRIPE_LIVE_STATUS_TTL` (see [tickets.md](tickets.md#where-the-payer-is-stored)).

**Order meta:** `_extrachill_application_fee` (the whole fee), `_extrachill_shipping_hold_minor` (the hold), `_extrachill_processing_fee_minor` (the processing part, `EXTRACHILL_SHOP_PROCESSING_FEE_META`) and `_extrachill_processing_payer` (`account` or `platform`).

**Settings:** the estimate is the shared `processing_percent` (2.9) and `processing_fixed_minor` (30) in the network `extrachill_commerce_fees` option, used by tickets and merch. They were `ticket_processing_percent` / `ticket_processing_fixed_minor` (#1553); `ec_commerce_fee_sanitize()` still reads the old keys when the new ones are missing, and the next save of the Payments page writes the new ones.

| Order (US card, 2.9% + 30¢) | Buyer pays | Commission | Processing | Fee, platform pays | Fee, `account` pays | Seller nets* | Extra Chill nets |
| --- | --- | --- | --- | --- | --- | --- | --- |
| $25 shirt + $5 shipping | $30.00 | $2.50 | $1.17 | $8.67 | $7.50 | $21.33 | $2.50 |
| $10 item + $5 shipping | $15.00 | $1.00 | $0.74 | $6.74 | $6.00 | $8.26 | $1.00 |
| $3 Ships Free sticker | $3.00 | $0.30 | $0.39 | $0.69 | $0.30 | $2.31 | $0.30 |

\* After the label is bought with the $5 hold; a seller who ships themselves gets the hold back ($26.33 and $13.26). Seller and Extra Chill nets are the same for both payers: on `account` the processor takes the processing from the seller; on platform-pays it is in the fee and the processor takes it from Extra Chill. Real processing on an ordinary US card can be up to a cent below the estimate (the estimate rounds up), so Extra Chill nets the commission or a cent more. Pricier cards (international, currency conversion) cost more than the estimate: on platform-pays Extra Chill absorbs the difference, on `account` the seller does.

**Refunds keep the processing part.** `extrachill_shop_merch_fee_refund_target()` returns the commission in proportion to the share refunded and the unspent shipping hold on a full refund, as before. The processing part (`_extrachill_processing_fee_minor`) is never returned, not even on a full refund: the processor does not return its fee on a refund, Extra Chill paid it, and a seller on `account` is in the same place. Full refund of the $30 order above, no label: the buyer gets $30, $7.50 of the $8.67 fee goes back to the seller, Extra Chill keeps $1.17 and pays $1.17 processing. Orders from before #1563 have no processing meta and refund as before.

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
- The seller confirms the parcel weight and dimensions (prefilled from product weights and dimensions, else 10×8×4 in, 1 lb), checks the price, then buys the **cheapest USPS rate** via Shippo
- The buyer's shipping charge (held in the application fee) pays for the label. If the label costs more, sellers must ship the order themselves; shop managers can buy it after confirming, and Extra Chill pays the difference
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
