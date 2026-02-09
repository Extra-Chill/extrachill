# AGENTS.md — Technical Reference

Technical implementation details for AI coding assistants and contributors.

## Architecture Overview

Procedural WordPress plugin extending WooCommerce with Extra Chill-specific functionality. Uses direct `require_once` includes (no PSR-4 autoloading).

## Lifetime Membership System

### User Meta Storage
WordPress-native user meta (KISS principle, no custom tables):

```php
// Meta key: extrachill_lifetime_membership
// Meta value (array):
array(
  'purchased' => '2024-10-27 14:30:00',  // MySQL datetime
  'order_id'  => 12345,                   // WooCommerce order ID
  'username'  => 'johndoe'                // Community username
)
```

### Integration Flow
1. **Product Page**: User enters community username (pre-filled if logged in)
2. **Add to Cart**: Username saved to cart item data
3. **Cart Display**: Username displayed with editable field
4. **Checkout**: Username added to order item metadata
5. **Payment Complete**: Order auto-completes (membership only orders)
6. **Order Completion**: Shop plugin calls `ec_create_lifetime_membership()` from users plugin
7. **Network-Wide Check**: `is_user_lifetime_member()` validates via user meta

### Clean Separation
- **extrachill-users** owns data operations (`ec_create_lifetime_membership()`, `is_user_lifetime_member()`)
- **extrachill-shop** owns WooCommerce UI (product fields, cart, checkout)

## Raffle Product System

### Tag-Based Activation
Features only activate when product has "raffle" tag.

### Admin Field
`inc/products/raffle/admin-fields.php`:
- Appears on WooCommerce Inventory tab
- MutationObserver detects tag changes in real-time
- Saves to `_raffle_max_tickets` post meta

### Frontend Counter
`inc/products/raffle/frontend-counter.php`:
- Hooks `woocommerce_single_product_summary` at priority 25
- Shows: "45/100 tickets remaining"
- Color states:
  - **>50%**: Green gradient
  - **25-50%**: Yellow/orange gradient
  - **<25%**: Red gradient with bold text

### Conditional Loading
`inc/core/assets.php`:
- Frontend CSS: Only on products with "raffle" tag (`has_term()` check)
- Admin CSS + JS: Only on `post.php` and `post-new.php`
- Cache busting via `filemtime()`

## Stripe Connect Integration

### Pattern
Uses "Separate Charges and Transfers" for secure platform processing.

### Flow
1. Artist onboards via Stripe Express dashboard
2. Platform processes payment
3. Transfers sent to artist Stripe accounts
4. Artists access dashboards via shop manager

## Shipping Label System

### Shippo Integration
- Automated USPS label generation
- Artists purchase labels from shop manager
- Automatic tracking number sync
- Customer notifications

### Pricing
$5.00 domestic flat-rate shipping.

## WooCommerce Styling

### CSS Organization
`assets/css/woocommerce.css` (492 lines):
- CSS Grid product layouts
- Theme button colors (#0b5394 primary, #083b6c hover)
- Dark mode via CSS custom properties
- Breakpoints: 768px, 600px, 480px

### Breadcrumb Integration
Uses `extrachill_breadcrumbs_override_trail` filter:
```
Extra Chill › Merch Store › Category › Product
```

### Cart Icon
Hooks `extrachill_header_top_right` at priority 25.

## Project Structure

```
extrachill-shop/
├── extrachill-shop.php          # Main plugin file
├── inc/
│   ├── core/
│   │   ├── assets.php           # Asset enqueuing
│   │   └── breadcrumb-integration.php
│   └── products/
│       ├── lifetime-membership.php  # Membership WooCommerce integration
│       └── raffle/
│           ├── admin-fields.php     # Max tickets field
│           └── frontend-counter.php # Progress bar
├── templates/
│   ├── shop-homepage.php        # Product grid
│   └── cart-icon.php            # Header icon
├── assets/
│   ├── css/
│   │   ├── woocommerce.css      # Main styling (492 lines)
│   │   ├── raffle-frontend.css  # Progress bar (135 lines)
│   │   └── raffle-admin.css     # Admin field (26 lines)
│   └── js/
│       └── raffle-admin.js      # Field visibility (53 lines)
└── build.sh                     # Production packaging
```

## Key Functions

| Function | Location | Purpose |
|----------|----------|---------|
| `ec_create_lifetime_membership()` | extrachill-users | Create membership record |
| `is_user_lifetime_member()` | extrachill-users | Validate membership |

## Security

- Input sanitization on all user input
- Output escaping (XSS protection)
- Capability checks for admin operations
- Prepared statements (SQL injection prevention)

## Testing Checklist

### Lifetime Membership
- [ ] Complete purchase flow
- [ ] Username validation at cart/checkout
- [ ] Cross-domain ad removal verification
- [ ] Multisite login persistence

### Raffle Products
- [ ] Tag addition shows admin field
- [ ] Tag removal hides admin field
- [ ] Max tickets saves correctly
- [ ] Progress bar displays on frontend
- [ ] Color states change with stock level
- [ ] Assets only load when needed

### Store Customization
- [ ] Breadcrumbs on all WooCommerce pages
- [ ] Cart icon links to shop
- [ ] Responsive breakpoints work
- [ ] Dark mode styling correct
