# Shop Launch Checklist

Complete checklist for launching shop.extrachill.com with artist marketplace functionality.

---

## Current Status: Feature-Rollout Gate

Shop access goes through the network feature-rollout primitive in `extrachill-users`. The old hardcoded `manage_options` checks are gone.

- **Code ceiling:** `ec_feature_ceilings()` in `extrachill-users/inc/core/gating.php` sets the highest tier the code is ready for. `shop` is `public`.
- **Live tier:** the network option `ec_feature_tier_shop` (`admin` | `team` | `public`) sets the current tier and is clamped to the ceiling by `ec_feature_tier()`. If the option is unset, the tier defaults to the ceiling.
- **Seller requirement:** `ec_can_manage_shop()` also requires that the user manages at least one artist, venue, or promoter.

Moving the shop between tiers is an operator flag flip, either from the Feature Rollout network admin screen or with `wp site option update ec_feature_tier_shop <tier>`. No code changes are needed.

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

- [ ] Set the `ec_feature_tier_shop` network option to the launch tier (see "Feature-Rollout Gate" above)
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
| Seller Shop Manager Block | `extrachill-shop/src/blocks/shop-manager/` |
| Artist Creator Block | `extrachill-artist-platform/src/blocks/artist-creator/` |
| Shop API Routes | `extrachill-api/inc/routes/shop/` |
| WooCommerce Templates | `extrachill-shop/woocommerce/` |
| Product Count Helper | `extrachill-shop/inc/core/artist-product-meta.php` |
| Secondary Header Nav | `extrachill-artist-platform/inc/core/nav.php` |
| Avatar Menu | `extrachill-users/inc/avatar-menu.php` |
| Storefront Header | `extrachill-shop/inc/core/seller-storefront.php` |
| Terms of Service Checklist | `extrachill-shop/terms-of-service-checklist.md` |
