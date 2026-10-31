# Shop Launch Checklist

Complete checklist for launching shop.extrachill.com with artist marketplace functionality.

---

## Current Status: Admin-Only Development Mode

The shop system is currently gated to admins only, allowing internal testing and dogfooding before public launch.

### Admin Gate Implementation (Completed)

- [x] **Navigation Gating**
  - [x] Secondary header shop links (Create Shop / Manage Shop) - admin only
  - [x] Avatar menu shop links - admin only
  - [x] Artist storefront "Manage Shop" button - admin only
  - [x] Artist creator success screen "Create Shop" button - admin only

- [x] **Helper Functions**
  - [x] `extrachill_shop_get_product_count_for_user()` - cross-site product count

### Files Modified for Admin Gate

| File | Change |
|------|--------|
| `extrachill-shop/inc/core/artist-product-meta.php` | Added user product count helper |
| `extrachill-artist-platform/inc/core/nav.php` | Admin-gated secondary header link |
| `extrachill-users/inc/avatar-menu.php` | Admin-gated avatar menu link |
| `extrachill-shop/inc/core/artist-storefront-manage-button.php` | Admin-gated storefront button |
| `extrachill-artist-platform/src/blocks/artist-creator/render.php` | Admin-gated config URL |
| `extrachill-artist-platform/src/blocks/artist-creator/view.js` | Conditional Create Shop button |

### To Remove Admin Gate (Pre-Launch)

Search for comments containing "Admin-only during development" or "ADMIN ONLY during development" and remove the `current_user_can('manage_options')` checks.

---

## Phase 1: Critical Blockers

Must complete before any public transactions.

### Payment System Testing

- [ ] **Stripe Connect End-to-End Test**
  - [ ] Create test artist profile
  - [ ] Complete Stripe Express onboarding flow
  - [ ] Verify account status updates via webhook
  - [ ] Create test product linked to artist
  - [ ] Complete purchase with real payment method
  - [ ] Verify destination charge splits correctly (90% artist / 10% platform)
  - [ ] Verify payout appears in artist's Stripe dashboard
  - [ ] Test with Stripe CLI for webhook reliability

- [ ] **Multi-Artist Cart Test**
  - [ ] Add products from 2+ different artists to cart
  - [ ] Complete checkout
  - [ ] Verify separate destination charges per artist
  - [ ] Verify correct commission applied to each

- [ ] **Refund Workflow Test**
  - [ ] Process full refund for single-artist order
  - [ ] Process full refund for multi-artist order
  - [ ] Verify refund reaches customer
  - [ ] Verify artist payout adjusted correctly

- [ ] **Edge Cases**
  - [ ] Cart with mixed artist products + platform products (ad-free license)
  - [ ] Artist with pending Stripe account attempts product publish (should block)
  - [ ] Order placed, artist Stripe account becomes restricted before fulfillment

### Legal Documents

- [ ] **Site Terms of Service**
  - [ ] Draft document (see terms-of-service-checklist.md)
  - [ ] Legal review
  - [ ] Create page on extrachill.com
  - [ ] Add footer link across all sites

- [ ] **Artist Platform Agreement**
  - [ ] Draft document (see terms-of-service-checklist.md)
  - [ ] Legal review
  - [ ] Create page on artist.extrachill.com or extrachill.com
  - [ ] Implement acceptance checkbox during artist onboarding
  - [ ] Implement acceptance checkbox during Stripe Connect onboarding
  - [ ] Store acceptance record with timestamp

- [ ] **Checkout Consent**
  - [ ] Add ToS agreement checkbox to checkout
  - [ ] Link to Terms of Service
  - [ ] Link to Privacy Policy
  - [ ] Block checkout if not checked

### Tax Configuration

- [ ] **WooCommerce Tax Setup**
  - [ ] Enable taxes in WooCommerce settings
  - [ ] Configure tax rates for nexus states (SC, TX if applicable)
  - [ ] Decide: Tax on shipping? (varies by state)
  - [ ] Test tax calculation on checkout
  - [ ] Verify tax appears on order receipts

- [ ] **Tax Documentation**
  - [ ] Document tax strategy for artists (they handle their own)
  - [ ] Include in Artist Platform Agreement

---

## Phase 2: High Priority

Should complete before launch, but not technically blocking.

### Shipping Integration (Shippo)

- [ ] **API Integration**
  - [ ] Create Shippo developer account
  - [ ] Add Shippo API endpoints to extrachill-api
    - [ ] `POST /shop/shipping/rates` - Get rate quotes
    - [ ] `POST /shop/shipping/labels` - Purchase label
    - [ ] `GET /shop/shipping/tracking/{tracking_number}` - Track package
  - [ ] Implement Shippo webhook for tracking updates

- [ ] **Artist Shop Manager Integration**
  - [ ] Add "Ship Order" flow to Orders tab
  - [ ] Package dimensions/weight input
  - [ ] Carrier rate comparison display
  - [ ] Label purchase with artist's Shippo account
  - [ ] Print label functionality
  - [ ] Auto-update order with tracking number

- [ ] **Artist Shippo Account Connection**
  - [ ] Add Shippo OAuth or API key input to artist settings
  - [ ] Store credentials securely (encrypted in post meta or wp-config)
  - [ ] Validate connection before allowing shipping

- [ ] **Order Status Flow**
  - [ ] Fix multi-artist order shipping (currently marks entire order complete)
  - [ ] Per-artist shipping status tracking
  - [ ] Customer notification with tracking info

### GDPR Compliance

- [ ] **Data Export**
  - [ ] Register personal data exporter for shop data
  - [ ] Include: orders, ad-free license, artist subscriber data

- [ ] **Data Deletion**
  - [ ] Register personal data eraser for shop data
  - [ ] Handle: order anonymization, subscriber removal

- [ ] **Consent Records**
  - [ ] Store ToS acceptance with timestamp
  - [ ] Store Artist Agreement acceptance with timestamp
  - [ ] Make consent records exportable

### Product Approval Workflow

- [ ] **Admin Review Queue**
  - [ ] Products created as "pending" by default
  - [ ] Admin notification for new product submissions
  - [ ] Admin tool to approve/reject products
  - [ ] Artist notification on approval/rejection

- [ ] **OR: Trust-Based System**
  - [ ] Remove approval requirement
  - [ ] Rely on ToS violations for removal
  - [ ] Add reporting mechanism for problematic products

---

## Phase 3: Nice to Have

Can launch without, add post-launch.

### Artist Experience

- [ ] Low stock notifications to artists
- [ ] Sales/revenue analytics in artist dashboard
- [ ] Partial refund support
- [ ] Product categories/tags management
- [ ] Bulk product operations

### Customer Experience

- [ ] Order tracking page
- [ ] Estimated delivery dates
- [ ] Gift options
- [ ] Wishlist functionality

### Platform Operations

- [ ] Admin dashboard for all artist orders
- [ ] Platform-wide sales analytics
- [ ] Automated tax reporting/filing (TaxJar upgrade)
- [ ] Customer support ticket system

---

## Pre-Launch Final Checks

One week before public launch:

### Technical

- [ ] All Stripe webhooks verified working in production
- [ ] SSL certificate valid on shop.extrachill.com
- [ ] WooCommerce email templates tested (order confirmation, shipping, etc.)
- [ ] Mobile checkout flow tested
- [ ] Cross-browser testing (Chrome, Safari, Firefox)
- [ ] Error monitoring in place (what happens when things fail?)

### Content

- [ ] Shop homepage content finalized
- [ ] At least one "Extra Chill" product listed (dogfooding)
- [ ] Product placeholder/empty states handled gracefully
- [ ] Artist storefront empty state handled gracefully

### Legal

- [ ] All legal documents published and linked
- [ ] Acceptance checkboxes implemented and tested
- [ ] Privacy Policy updated for e-commerce data collection
- [ ] Cookie consent if required (check EU requirements)

### Business

- [ ] Stripe account in live mode (not test mode)
- [ ] Tax rates configured for live transactions
- [ ] Customer support email/process defined
- [ ] Refund policy understood by team
- [ ] First artist(s) onboarded and ready

---

## Launch Day

- [ ] Remove admin gate from shop navigation (see "To Remove Admin Gate" section above)
- [ ] Rebuild artist-platform block assets after JS changes
- [ ] Switch Stripe to live mode
- [ ] Announce launch (newsletter, socials)
- [ ] Monitor for errors first 24 hours
- [ ] First order celebration

---

## Post-Launch (First 30 Days)

- [ ] Review first orders for issues
- [ ] Gather artist feedback on shop manager
- [ ] Gather customer feedback on checkout
- [ ] Address any critical bugs immediately
- [ ] Document lessons learned
- [ ] Plan Phase 3 features based on real usage

---

## File References

| Component | Location |
|-----------|----------|
| Stripe Connect | `extrachill-shop/inc/stripe/` |
| Checkout Handler | `extrachill-shop/inc/stripe/checkout-handler.php` |
| Webhooks | `extrachill-shop/inc/stripe/webhooks.php` |
| Artist Shop Manager Block | `extrachill-artist-platform/src/blocks/artist-shop-manager/` |
| Artist Creator Block | `extrachill-artist-platform/src/blocks/artist-creator/` |
| Shop API Routes | `extrachill-api/inc/routes/shop/` |
| WooCommerce Templates | `extrachill-shop/woocommerce/` |
| Product Count Helper | `extrachill-shop/inc/core/artist-product-meta.php` |
| Secondary Header Nav | `extrachill-artist-platform/inc/core/nav.php` |
| Avatar Menu | `extrachill-users/inc/avatar-menu.php` |
| Storefront Button | `extrachill-shop/inc/core/artist-storefront-manage-button.php` |
| Terms of Service Checklist | `extrachill-shop/terms-of-service-checklist.md` |
