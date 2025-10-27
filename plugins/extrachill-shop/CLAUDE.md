# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The **Extra Chill Shop** plugin provides WooCommerce integration and e-commerce functionality for the Extra Chill platform. This plugin extends WooCommerce with platform-specific features including a cross-domain ad-free license system, custom breadcrumbs, product category navigation, and comprehensive WooCommerce styling.

## What's Implemented

### Cross-Domain Ad-Free License System
- **Purchase Processing**: Handles ad-free license purchases via WooCommerce order completion hook
- **Database Storage**: Custom `extrachill_ad_free` table tracks licenses by community username
- **Product Integration**: Community username field on product page (Product ID: 90123)
- **Cart Validation**: Username validation throughout checkout flow
- **Auto-Completion**: Ad-free license orders auto-complete after payment
- **Cross-Site Validation**: Works with `is_user_ad_free()` function in extrachill-users plugin

**Implementation**: `inc/core/ad-free-license.php` (149 lines)

### Breadcrumb Integration
- **Theme Integration**: Uses theme's unified `extrachill_breadcrumbs()` system via `extrachill_breadcrumbs_override_trail` filter
- **Consistent Structure**: "Extra Chill › Merch Store › [context]" matching platform standards
- **Hierarchical Support**: Parent category chains for product categories
- **Context-Aware**: Shop, product, cart, checkout, account page breadcrumbs
- **Single Source of Truth**: Same breadcrumb architecture as bbPress and other plugins

**Implementation**: `inc/core/breadcrumb-integration.php` (77 lines)

### Cart Icon
- **Header Integration**: Simple cart icon in site header
- **Theme Hook**: Hooks into `extrachill_header_top_right` at priority 25
- **Shop Link**: Links to WooCommerce shop page
- **FontAwesome SVG**: Uses theme's FontAwesome sprite

**Implementation**: `templates/cart-icon.php` (35 lines)

### Product Category Header
- **Secondary Navigation**: Category navigation below main header
- **WooCommerce Pages**: Displays on shop, cart, checkout, and product pages
- **Dynamic Categories**: Auto-generates links from product categories
- **Sorted by Count**: Categories ordered by product count (most popular first)

**Implementation**: `templates/product-category-header.php` (48 lines)

### WooCommerce Styling
- **Comprehensive CSS**: 590 lines of WooCommerce styling
- **Product Grid**: CSS Grid layout with responsive breakpoints
- **Responsive Design**: Mobile-optimized layouts (768px, 600px, 480px breakpoints)
- **Theme Integration**: Uses theme's standard button colors (#0b5394 primary, #083b6c hover)
- **Dark Mode Support**: Uses CSS custom properties from theme
- **Complete Coverage**: Shop, product, cart, checkout, breadcrumbs, buttons

**Implementation**: `assets/css/woocommerce.css` (590 lines)

### User Meta Storage
- **No Custom Tables**: Uses WordPress-native user meta for ad-free licenses
- **Meta Key**: `extrachill_ad_free_purchased` (array with purchased date, order_id, username)
- **Migration Utility**: One-time migration from legacy table via URL parameter

**Implementation**: `inc/core/ad-free-license.php` (includes migration utility)

## Architecture

### Plugin Structure
```
extrachill-shop/
├── extrachill-shop.php          # Main plugin file (singleton pattern)
├── inc/
│   └── core/                    # Core functionality
│       ├── ad-free-license.php      # Ad-free license purchase processing & migration
│       ├── assets.php               # CSS/JS enqueuing (conditional loading)
│       └── breadcrumb-integration.php # Breadcrumb customization
├── templates/                   # All template files
│   ├── archive-product.php          # WooCommerce product archive template
│   ├── cart-icon.php                # Header cart icon template
│   ├── content-single-product.php   # Product content template
│   ├── product-category-header.php  # Category navigation template
│   └── single-product.php           # Single product template
├── assets/
│   └── css/
│       └── woocommerce.css      # WooCommerce styling (590 lines)
├── composer.json                # Dev dependencies only (PHPCS, PHPUnit)
├── build.sh                     # Symlink to ../../.github/build.sh
├── .buildignore                 # Build exclusion patterns
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
- Loads 3 core includes via `load_includes()` method in `plugins_loaded` hook:
  1. `inc/core/ad-free-license.php` - Purchase processing, validation, and migration
  2. `inc/core/breadcrumb-integration.php` - Breadcrumb customization
  3. `inc/core/assets.php` - Asset enqueuing
- Loads 2 template files:
  1. `templates/cart-icon.php` - Header cart icon
  2. `templates/product-category-header.php` - Category navigation
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

The plugin integrates with the `extrachill` theme via WordPress action hooks:

### Theme Hooks Used
- **`extrachill_header_top_right`** (priority 25): Cart icon display
- **`extrachill_after_header`**: Product category navigation

### Template System Integration
The shop plugin relies on the theme's WooCommerce bypass in `inc/core/template-router.php`. The theme detects WooCommerce pages via `is_woocommerce()`, `is_cart()`, `is_checkout()`, and `is_account_page()` checks and returns templates unchanged, allowing WooCommerce's native template hierarchy to function. This follows the same bypass pattern as bbPress integration used by the community plugin.

**Key Implementation**:
- Theme bypasses routing for all WooCommerce pages before `is_front_page()` check
- Allows Shop page to be set as homepage via Settings → Reading without theme interference
- WooCommerce handles all template loading using its own template hierarchy
- Shop plugin provides CSS styling via `assets/css/woocommerce.css` (590 lines)

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
- **Template Override System**: No comprehensive template override functionality beyond two basic templates
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
- [ ] Database record creation on order completion
- [ ] Cross-domain license validation with `is_user_ad_free()`
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
