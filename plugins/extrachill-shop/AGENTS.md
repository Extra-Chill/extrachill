# CLAUDE.md

This file provides guidance to AI Agents when working with code in this repository.

## Project Overview

The **Extra Chill Shop** plugin provides WooCommerce integration and e-commerce functionality for the Extra Chill platform. This plugin extends WooCommerce with platform-specific features including a cross-domain ad-free license system, custom breadcrumbs, product category navigation, and comprehensive WooCommerce styling.

## What's Implemented

### Cross-Domain Ad-Free License System
- **Purchase Processing**: Handles ad-free license purchases via WooCommerce order completion hook
- **WordPress-Native Storage**: Uses user meta (no custom tables)
- **Product Integration**: Community username field on product page (Product ID: 90123)
- **Cart Validation**: Username validation throughout checkout flow
- **Auto-Completion**: Ad-free license orders auto-complete after payment
- **Cross-Site Validation**: Works with `is_user_ad_free()` function in extrachill-users plugin
- **Clean Separation**: Shop plugin handles WooCommerce UI, users plugin manages license data

**Implementation**: `inc/products/ad-free-license.php` (141 lines)

### Raffle Product System
- **Tag-Based Activation**: Features only activate when product has "raffle" tag
- **Admin Field**: Conditional "Max Raffle Tickets" field on WooCommerce inventory tab
- **Frontend Counter**: Progress bar showing remaining tickets on product pages
- **Progress States**: Visual color states - high stock (>50%) green, medium (25-50%) yellow/orange, low (<25%) red urgency
- **Dark Mode Support**: Full dark mode styling with responsive design
- **Conditional Loading**: Assets only load when needed (admin screen or raffle products)

**Implementation**:
- `inc/products/raffle/admin-fields.php` (45 lines) - Admin field with conditional display
- `inc/products/raffle/frontend-counter.php` (61 lines) - Progress bar display
- `assets/css/raffle-admin.css` (26 lines) - Admin field visibility styling
- `assets/css/raffle-frontend.css` (135 lines) - Progress bar with color states and responsive design
- `assets/js/raffle-admin.js` (53 lines) - MutationObserver for tag-based field visibility

### Breadcrumb Integration
- **Theme Integration**: Uses theme's unified `extrachill_breadcrumbs()` system via `extrachill_breadcrumbs_override_trail` filter
- **Consistent Structure**: "Extra Chill › Merch Store › [context]" matching platform standards
- **Hierarchical Support**: Parent category chains for product categories
- **Context-Aware**: Shop, product, cart, checkout, account page breadcrumbs
- **Single Source of Truth**: Same breadcrumb architecture as bbPress and other plugins

**Implementation**: `inc/core/breadcrumb-integration.php` (180 lines)

### Cart Icon
- **Header Integration**: Simple cart icon in site header
- **Theme Hook**: Hooks into `extrachill_header_top_right` at priority 25
- **Shop Link**: Links to WooCommerce shop page
- **FontAwesome SVG**: Uses theme's FontAwesome sprite

**Implementation**: `inc/templates/cart-icon.php` (29 lines)


### WooCommerce Styling
- **Comprehensive CSS**: 513 lines of WooCommerce styling
- **Product Grid**: CSS Grid layout with responsive breakpoints
- **Responsive Design**: Mobile-optimized layouts (768px, 600px, 480px breakpoints)
- **Theme Integration**: Uses theme's standard button colors (#0b5394 primary, #083b6c hover)
- **Dark Mode Support**: Uses CSS custom properties from theme
- **Complete Coverage**: Shop, product, cart, checkout, breadcrumbs, buttons

**Implementation**: `assets/css/woocommerce.css` (492 lines)

### Asset Management
- **Conditional Loading**: WooCommerce CSS only loads on WooCommerce pages (including when shop is homepage)
- **Raffle Frontend**: CSS only loads on product pages with "raffle" tag
- **Raffle Admin**: CSS + JS only load on product edit screen
- **Cache Busting**: Uses `filemtime()` for all assets
- **File Existence Checks**: Verifies files exist before enqueuing

**Implementation**: `inc/core/assets.php` (82 lines)

### Hybrid Template System
- **Hybrid Approach**: Combines theme's homepage override with WooCommerce template filters
- **Shop Homepage**: Custom via `extrachill_template_homepage` filter (same as chat, events, stream)
- **Single Products**: WooCommerce via `template_include` filter (priority 99)
- **Template Parts**: WooCommerce via `woocommerce_locate_template` filter
- **Best of Both**: Simple homepage control + full WooCommerce support elsewhere

**Template Loading**:
- Shop homepage uses `extrachill_template_homepage` filter (blog ID 3)
- Single products, cart, and checkout use `template_include` filter (priority 99)
- Template parts use `woocommerce_locate_template` filter (priority 10)

**Template Files**:
- `inc/templates/shop-homepage.php` (71 lines) - Shop homepage with breadcrumbs and product grid
- `woocommerce/single-product.php` (62 lines) - Individual product page wrapper
- `woocommerce/content-product.php` (67 lines) - Product cards in shop grid (used by homepage)
- `woocommerce/content-single-product.php` (76 lines) - Single product content
- `woocommerce/cart.php` (67 lines) - Cart page template
- `woocommerce/checkout.php` (76 lines) - Checkout page template
- `woocommerce/single-product/tabs/tabs.php` (29 lines) - Product tabs template

**Template Filters**:
- `extrachill_template_homepage` (priority 10, main plugin file) - Shop homepage only (blog ID 3)
- `template_include` (priority 99, woocommerce-templates.php) - Single products, cart, and checkout pages
- `woocommerce_locate_template` (priority 10, woocommerce-templates.php) - All template parts

## Architecture

### Plugin Structure
```
extrachill-shop/
├── extrachill-shop.php          # Main plugin file (singleton + homepage override filter)
├── inc/
│   ├── core/                    # Core functionality
│   │   ├── assets.php               # CSS/JS enqueuing (conditional loading)
│   │   ├── breadcrumb-integration.php # Breadcrumb customization
│   │   └── woocommerce-templates.php # WooCommerce template filters
│   ├── products/                # Product customizations
│   │   ├── ad-free-license.php      # Ad-free license WooCommerce integration
│   │   └── raffle/              # Raffle product features
│   │       ├── admin-fields.php     # Conditional admin field
│   │       └── frontend-counter.php # Progress bar display
│   └── templates/               # Plugin templates
│       ├── shop-homepage.php        # Shop homepage with product grid
│       └── cart-icon.php            # Header cart icon template
├── woocommerce/                 # WooCommerce template overrides
│   ├── single-product.php           # Single product page wrapper
│   ├── content-product.php          # Product cards in shop grid (used by homepage)
│   ├── content-single-product.php   # Single product content
│   ├── cart.php                     # Cart page template
│   ├── checkout.php                 # Checkout page template
│   └── single-product/
│       └── tabs/
│           └── tabs.php             # Product tabs template
├── assets/
│   ├── css/
│   │   ├── woocommerce.css          # WooCommerce styling (492 lines)
│   │   ├── raffle-frontend.css      # Raffle progress bar (135 lines)
│   │   └── raffle-admin.css         # Raffle admin field (26 lines)
│   └── js/
│       └── raffle-admin.js          # Raffle field visibility (53 lines)
├── composer.json                # Dev dependencies only (PHPCS, PHPUnit)
├── build.sh                     # Symlink to ../../.github/build.sh
├── .buildignore                 # Build exclusion patterns
├── .gitignore                   # Git ignore patterns
└── CLAUDE.md                    # This file
```

### Development Standards
- **Procedural Pattern**: Uses direct `require_once` includes for all functionality (no PSR-4 autoloading)
- **WordPress Hooks**: Extensive use of actions/filters for extensibility
- **Security First**: Input sanitization, output escaping, nonces, prepared statements
- **Conditional Loading**: Assets only load on WooCommerce pages
- **Modular Files**: Single responsibility principle for all includes

### Loading Pattern
**Main Plugin File** (`extrachill-shop.php`):
- Singleton pattern with private constructor
- Defines plugin constants (VERSION, PLUGIN_FILE, PLUGIN_DIR, PLUGIN_URL, PLUGIN_BASENAME)
- Loads 7 includes via `load_includes()` method in `plugins_loaded` hook:
  1. `inc/products/ad-free-license.php` - Ad-free license WooCommerce integration
  2. `inc/products/raffle/admin-fields.php` - Raffle admin field
  3. `inc/products/raffle/frontend-counter.php` - Raffle progress counter
  4. `inc/core/woocommerce-templates.php` - WooCommerce template filters
  5. `inc/core/breadcrumb-integration.php` - Breadcrumb customization
  6. `inc/core/assets.php` - Asset enqueuing
  7. `inc/templates/cart-icon.php` - Header cart icon
- Homepage override filter registered after plugin initialization:
  - `extrachill_template_homepage` filter at priority 10
  - Returns `inc/templates/shop-homepage.php` on blog ID 3
- Activation hook sets activation flag and flushes rewrite rules
- Deactivation hook flushes rewrite rules

## Development Commands

### Build and Deployment
```bash
# Build production ZIP package
./build.sh

# Install dependencies
composer install

# Run PHP linting
composer run lint

# Fix code style issues
composer run lint:fix

# Run tests (when implemented)
composer run test
```

### Build Output
- **Production Build**: Creates `/build/extrachill-shop/` directory and `/build/extrachill-shop.zip` file
- **Non-Versioned**: Follows platform standard (no version numbers in filenames)
- **Universal Build Script**: Uses shared build script at `../../.github/build.sh`
- **File Exclusion**: `.buildignore` rsync patterns exclude development files

## Ad-Free License System

### Architecture
- **WordPress-Native Storage**: Uses user meta (KISS principle, no custom tables)
- **Username → User ID Mapping**: Handled by `ec_create_ad_free_license()` in extrachill-users plugin
- **Network-Wide Availability**: User meta accessible from all sites in multisite network
- **Validation Function**: `is_user_ad_free()` in extrachill-users plugin (reads user meta)
- **Creation Function**: `ec_create_ad_free_license()` in extrachill-users plugin (writes user meta)
- **WooCommerce Integration**: Lives in extrachill-shop plugin (product fields, cart, checkout UI)
- **Purchase Handler**: Calls extrachill-users creation function after order completion
- **Clean Separation**: Users plugin owns data operations, shop plugin owns WooCommerce UI only
- **Theme Integration**: Theme calls `is_user_ad_free()` to block ads for license holders

### User Meta Structure
```php
// Meta key: extrachill_ad_free_purchased
// Meta value (array):
array(
  'purchased' => '2024-10-27 14:30:00',  // MySQL datetime
  'order_id'  => 12345,                   // WooCommerce order ID
  'username'  => 'johndoe'                // Community username
)
```

### Purchase Flow
1. **Product Page**: User enters community username (pre-filled if logged in)
2. **Add to Cart**: Username saved to cart item data
3. **Cart Display**: Username displayed with editable field
4. **Checkout**: Username added to order item metadata
5. **Payment Complete**: Order auto-completes (ad-free license only orders)
6. **Order Completion**: `extrachill_shop_handle_ad_free_purchase()` calls `ec_create_ad_free_license()` from users plugin
7. **Network-Wide Check**: `is_user_ad_free()` validates license via user meta

### Security
- **Username Sanitization**: `sanitize_text_field()` on all username inputs
- **Prepared Statements**: `$wpdb->prepare()` for all database queries
- **WordPress Multisite**: Native multisite authentication for cross-domain sessions
- **Order Validation**: Verifies order object and product ID before processing

## Plugin Dependencies

### Required
- **WordPress**: 5.0+ multisite network
- **PHP**: 7.4+
- **WooCommerce**: Must be active for e-commerce functionality
- **ExtraChill Theme**: For theme hook integration (`extrachill_header_top_right`, `extrachill_after_header`)

### Development
- **Composer**: Dependency management for dev tools
- **PHP CodeSniffer**: Code quality and WordPress standards compliance
- **PHPUnit**: Unit testing framework (tests not yet implemented)

### Optional Integration
- **extrachill-users**: Provides `is_user_ad_free()` function for license validation
- **extrachill-multisite**: Network infrastructure for cross-domain functionality

## Integration with Main Theme

The plugin integrates with the `extrachill` theme via filters and action hooks:

### Theme Hooks Used
- **`extrachill_template_homepage`** (priority 10): Homepage template override (same pattern as chat, events, stream)
- **`extrachill_header_top_right`** (priority 25): Cart icon display
- **`extrachill_breadcrumbs_root`**: Custom breadcrumb root ("Extra Chill › Merch Store")
- **`extrachill_breadcrumbs_override_trail`**: Context-specific breadcrumb trails
- **`extrachill_back_to_home_label`**: "← Back to Merch Store" label

### Homepage Override Pattern
The shop plugin uses the theme's `extrachill_template_homepage` filter to override the homepage on shop.extrachill.com (blog ID 3). This follows the same architectural pattern as chat, events, and stream plugins:

**Key Implementation**:
- Single filter function checks blog ID 3 and returns custom template path
- Works regardless of Settings → Reading configuration (static page or posts page)
- Plugin has complete control over homepage rendering with custom WP_Query
- WooCommerce templates still used for cart, checkout, account, and single product pages
- Shop plugin provides CSS styling via `assets/css/woocommerce.css` (513 lines)

### CSS Custom Properties
The WooCommerce CSS uses theme custom properties:
- `--background-color` - Background colors
- `--text-color` - Primary text color
- `--link-color` - Link and accent colors
- `--muted-text` - Secondary text
- `--border-color` - Borders and dividers
- `--accent` - Primary accent color
- `--accent-2` - Secondary accent color
- `--card-background` - Card backgrounds
- `--card-hover-shadow` - Card hover shadows

**Button Styling**: Uses hardcoded colors matching theme's button system (#0b5394 primary, #083b6c hover) rather than custom properties.

## Known Limitations

### Not Yet Implemented
- **Advanced Asset Optimization**: No context detection helpers or safe wrapper functions
- **Admin Settings Page**: No WP Admin interface for plugin settings
- **Analytics Integration**: No purchase tracking or conversion analytics
- **Error Logging**: Basic error_log() only, no structured logging system

### Future Enhancements
- Admin settings page for product ID configuration
- Enhanced analytics and reporting
- Additional payment gateway integrations
- Mobile app API endpoints
- Advanced product customization options

## Development Workflow

### Local Development
1. Edit files directly in WordPress plugin directory
2. Test on shop.extrachill.com subdomain
3. Verify cross-domain ad-free license validation
4. Run `composer run lint` before committing

### Build Process
1. Run `./build.sh` to create production package
2. Script creates clean `/build/extrachill-shop/` directory
3. Script creates `/build/extrachill-shop.zip` file (non-versioned)
4. Upload ZIP to WordPress admin or deploy via pipeline

### Testing Checklist
- [ ] Ad-free license purchase flow end-to-end
- [ ] Username validation in cart and checkout
- [ ] Cross-domain license validation with `is_user_ad_free()`
- [ ] Raffle admin field visibility (only shows when "raffle" tag present)
- [ ] Raffle progress bar display on raffle products
- [ ] Raffle progress bar color states (high/medium/low stock)
- [ ] Raffle frontend CSS loading (only on raffle products)
- [ ] Raffle admin assets loading (only on product edit screen)
- [ ] Breadcrumb display on all WooCommerce pages
- [ ] Cart icon display in header
- [ ] Product category navigation rendering
- [ ] WooCommerce CSS loading and styling
- [ ] Responsive design at all breakpoints
- [ ] Dark mode styling

## Security Implementation

- **Input Sanitization**: All user input sanitized with `sanitize_text_field()`
- **Output Escaping**: `esc_html()`, `esc_attr()`, `esc_url()` throughout
- **Database Security**: Prepared statements via `$wpdb->prepare()`
- **Capability Checks**: Admin functions check user capabilities
- **WordPress Multisite**: Native multisite authentication for cross-domain sessions

## User Info

- Name: Chris Huber
- Dev website: https://chubes.net
- GitHub: https://github.com/chubes4
- Founder & Editor: https://extrachill.com
- Creator: https://saraichinwag.com
