# Extra Chill Shop - Agent Development Guide

## Overview

WordPress plugin providing comprehensive e-commerce functionality for shop.extrachill.com (Blog ID 3). Integrates WooCommerce with artist marketplace features, Stripe Connect payouts, and cross-domain ad-free license validation.

## Plugin Information

- **Name**: Extra Chill Shop
- **Version**: 0.5.0
- **Text Domain**: `extrachill-shop`
- **Author**: Chris Huber
- **License**: GPL v2 or later
- **Requires at least**: 5.0
- **Tested up to**: 6.4
- **Requires PHP**: 7.4

## Architecture

### Core Features

#### WooCommerce Integration
- Homepage override using `extrachill_homepage_content` action hook
- Template system combining theme filters with WooCommerce template overrides
- Comprehensive WooCommerce styling with responsive design
- Cart icon integration in site header via `inc/templates/cart-icon.php`
- Breadcrumb customization using theme's breadcrumb system
- Filter bar integration via `inc/core/shop-filter-bar.php`

#### Artist Marketplace
- Artist taxonomy for product attribution (`inc/core/artist-taxonomy.php`)
- Artist product meta management (`inc/core/artist-product-meta.php`)
- Artist storefront management buttons (`inc/core/artist-storefront-manage-button.php`)
- Commission settings for platform fees (`inc/core/commission-settings.php`)
- Order notifications to artists for their products (`inc/core/artist-order-notifications.php`)

#### Stripe Connect Integration
- Artist onboarding via Stripe Connect Express accounts (`inc/stripe/stripe-connect.php`)
- Payment integration with WooCommerce using "Separate Charges and Transfers" pattern (`inc/stripe/payment-integration.php`)
- Payouts handled via destination charges to connected artist accounts
- Webhook processing for account updates and payout events (`inc/stripe/webhooks.php`)

#### Shipping System
- Shippo API integration for automated shipping labels (`inc/shipping/shippo-client.php`)
- Artist-managed shipping addresses stored on artist profiles
- Automated cheapest USPS rate selection for domestic shipments
- Flat-rate shipping charge ($5.00) integrated into checkout
- "Ships Free" logic for small items (e.g., stickers) that bypasses flat-rate shipping when the entire artist portion of the cart consists of free-shipping items
- Label reprinting and tracking synchronization with WooCommerce orders

#### Lifetime Extra Chill Membership System
- Cross-domain membership validation using user meta storage (provides ad-free benefit)
- Membership product auto-provisioned via SKU (`ec-lifetime-membership`)
- Product type definition (`inc/products/lifetime-membership-product.php`)
- Purchase processing via WooCommerce order completion hooks (`inc/products/lifetime-membership.php`)
- Integration with `is_user_lifetime_member()` function from extrachill-users plugin

#### Raffle System
- Raffle product type with admin fields (`inc/products/raffle/admin-fields.php`)
- Frontend progress counters and stock visualization (`inc/products/raffle/frontend-counter.php`)

### File Organization

```
extrachill-shop/
├── extrachill-shop.php              # Main plugin file (singleton pattern)
├── inc/
│   ├── core/
│   │   ├── assets.php               # Asset enqueuing (CSS/JS)
│   │   ├── breadcrumb-integration.php # Breadcrumb customization
│   │   ├── woocommerce-templates.php  # WooCommerce template filters
│   │   ├── nav.php                  # Navigation integration
│   │   ├── shop-filter-bar.php      # Filter bar integration
│   │   ├── artist-taxonomy.php      # Artist taxonomy registration
│   │   ├── artist-product-meta.php  # Artist product attribution
│   │   ├── artist-storefront-manage-button.php # Storefront management
│   │   ├── artist-order-notifications.php # Order email notifications
│   │   ├── commission-settings.php  # Platform commission configuration
│   │   └── filters/
│   │       └── button-classes.php   # WooCommerce button styling
│   ├── products/
│   │   ├── lifetime-membership-product.php # Lifetime Membership product type (ad-free)
│   │   ├── lifetime-membership.php      # Membership system and WooCommerce hooks
│   │   └── raffle/
│   │       ├── admin-fields.php     # Raffle admin configuration
│   │       └── frontend-counter.php # Raffle progress display
│   ├── stripe/
│   │   ├── stripe-connect.php       # Stripe Connect OAuth
│   │   ├── payment-integration.php  # WooCommerce payment integration
│   │   ├── checkout-handler.php     # Checkout processing
│   │   └── webhooks.php             # Stripe webhook handlers
│   ├── shipping/
│   │   ├── shipping-settings.php    # Shipping configuration
│   │   ├── shippo-client.php        # Shippo API client
│   │   └── checkout-shipping.php    # Checkout shipping integration
│   └── templates/
│       ├── shop-homepage.php        # Homepage template
│       └── cart-icon.php            # Header cart icon
├── woocommerce/                     # WooCommerce template overrides
│   ├── archive-product.php          # Product archive
│   ├── content-product.php          # Product card in loops
│   ├── content-single-product.php   # Single product content
│   ├── single-product.php           # Single product wrapper
│   ├── checkout.php                 # Checkout page
│   ├── cart.php                     # Cart page
│   ├── loop/
│   │   └── header.php               # Archive header
│   ├── cart/
│   │   ├── cart.php                 # Cart contents
│   │   └── proceed-to-checkout-button.php # Checkout button
│   └── single-product/
│       ├── add-to-cart/
│       │   └── simple.php           # Simple product add to cart
│       └── tabs/
│           └── tabs.php             # Product tabs
├── assets/                          # CSS/JS assets
├── docs/
│   └── CHANGELOG.md                 # Version history
└── build.sh                         # Symlink to /.github/build.sh
```

### Loading Pattern

The plugin uses a singleton pattern with OOP architecture:

```php
class ExtraChillShop {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    public function init() {
        $this->load_includes();
    }
}
```

**Include Categories**:
1. Product customizations (lifetime membership, raffle)
2. Core functionality (templates, breadcrumbs, assets, nav, filters)
3. Artist marketplace (taxonomy, product meta, commission, notifications)
4. Stripe Connect integration (connect, checkout, webhooks, payment)
5. Shipping integration (settings, Shippo client, checkout)
6. Templates (cart icon)

### REST API Integration

The shop plugin consumes endpoints from the centralized extrachill-api plugin:

**Shop Endpoints Used:**
- `GET/POST/PUT/DELETE /shop/products` - Product CRUD operations
- `GET/POST/DELETE /shop/orders` - Artist order management
- `POST/DELETE /shop/products/{id}/images` - Product image management
- `GET/POST/DELETE /shop/stripe` - Stripe Connect management
- `POST /shop/stripe-webhook` - Stripe webhook handler
- `GET/PUT /shop/shipping-address` - Artist shipping address
- `GET/POST /shop/shipping-labels` - Shipping label purchase

### Cross-Plugin Integration

**extrachill-users Plugin:**
- `is_user_lifetime_member()` - Membership validation function
- User meta storage for membership status (ad-free)

**extrachill-api Plugin:**
- All REST API endpoints for shop operations
- Stripe webhook processing

**extrachill-artist-platform Plugin:**
- Artist profile data for product attribution
- Artist permission validation
- Stripe Connect account association

**extrachill theme:**
- `extrachill_homepage_content` action for homepage override
- Breadcrumb system integration
- Filter bar component integration

## Homepage Integration

The shop homepage is rendered via the theme's action hook system:

```php
function extrachill_shop_render_homepage() {
    include EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/templates/shop-homepage.php';
}
add_action( 'extrachill_homepage_content', 'extrachill_shop_render_homepage', 10 );
```

## Dependencies

**Required:**
- **WooCommerce** - E-commerce platform (declared in plugin header)
- **extrachill theme** - Template integration and hooks
- **WordPress**: 5.0+
- **PHP**: 7.4+

**Optional:**
- **extrachill-users plugin** - License validation functions
- **extrachill-api plugin** - REST API infrastructure
- **extrachill-artist-platform plugin** - Artist data integration
- **Stripe** - Payment processing
- **Shippo** - Shipping label API

## Build System

**Build Script**: Symlinked to `/.github/build.sh`

**Build Output**: `/build/extrachill-shop.zip` file only

**Process**:
1. Clean previous builds
2. Install production dependencies: `composer install --no-dev`
3. Copy essential files to temporary build directory
4. Create ZIP archive
5. Remove temporary directory
6. Restore development dependencies
