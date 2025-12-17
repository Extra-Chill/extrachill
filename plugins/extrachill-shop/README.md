# Extra Chill Shop

WordPress plugin providing WooCommerce integration and e-commerce functionality for the Extra Chill platform. Features cross-domain ad-free license system, custom breadcrumbs, product category navigation, and comprehensive WooCommerce styling.

## 🛒 Overview

The Extra Chill Shop plugin extends WooCommerce with ExtraChill-specific functionality, including a unique cross-domain ad-free license system that allows purchases on `shop.extrachill.com` to disable ads on `extrachill.com`.

## Development Status

- **Active Development**: The shop plugin is still evolving, especially around cross-domain ad-free licensing and raffle workflows. Expect interface adjustments, layout refinements, and configuration tweaks before the next production-ready release.
- **Deployment Guidance**: Use staging sites for verification; ongoing work includes deeper shop-theme integration, performance tuning, and documentation of new helper hooks.

## ✨ Key Features

### 🔐 Cross-Domain Ad-Free License System
- **Multi-Domain Integration**: Purchases on shop site affect ad display on main site
- **WordPress Multisite**: Native multisite authentication for seamless cross-domain user sessions
- **License Management**: Automated license activation and validation via user meta
- **Community Integration**: Links purchases to community usernames
- **Clean Architecture**: Shop plugin handles WooCommerce UI, users plugin manages license data

### 🎟️ Raffle Product System
- **Tag-Based Activation**: Features only activate when product has "raffle" tag
- **Admin Field**: Conditional "Max Raffle Tickets" field on WooCommerce inventory tab
- **Frontend Progress Bar**: Visual countdown showing remaining tickets on product pages
- **Color States**: High stock (>50%) green, medium (25-50%) yellow/orange, low (<25%) red urgency
- **Smart Loading**: Assets only load when needed (admin screen or raffle products)
- **Dark Mode Support**: Full dark mode styling with responsive design
- **MutationObserver**: Real-time field visibility based on tag presence

### 🎨 Store Customization
- **Unified Breadcrumbs**: Integrates with theme's breadcrumb system via filter for consistent "Extra Chill › Merch Store" structure
- **Cart Icon Integration**: Header cart icon linking to shop
- **Comprehensive Styling**: 492 lines of WooCommerce CSS with responsive design (matched to current asset)

### ⚡ WooCommerce Styling
- **Product Grid**: CSS Grid layout with responsive breakpoints
- **Theme Integration**: Uses theme's standard button colors (#0b5394 primary, #083b6c hover)
- **Dark Mode Support**: CSS custom properties from theme
- **Mobile Optimized**: Breakpoints at 768px, 600px, 480px
- **Complete Coverage**: Shop, product, cart, checkout, breadcrumbs, buttons

## 🏗️ Architecture

### Plugin Structure
```
extrachill-shop/
├── extrachill-shop.php          # Main plugin file
├── inc/                         # Core functionality
│   ├── core/                    # Core plugin files
│   │   ├── assets.php               # Asset enqueuing
│   │   └── breadcrumb-integration.php # Breadcrumb customization
│   ├── products/                # Product customizations
│   │   ├── ad-free-license.php      # Ad-free license WooCommerce integration
│   │   └── raffle/              # Raffle product features
│   │       ├── admin-fields.php     # Conditional admin field
│   │       └── frontend-counter.php # Progress bar display
│   └── templates/               # Plugin templates
│       ├── shop-homepage.php        # Shop homepage with product grid
│       └── cart-icon.php            # Header cart icon
├── assets/                      # CSS/JS assets
│   ├── css/
│   │   ├── woocommerce.css          # WooCommerce styling (492 lines)
│   │   ├── raffle-frontend.css      # Raffle progress bar (135 lines)
│   │   └── raffle-admin.css         # Raffle admin field (26 lines)
│   └── js/
│       └── raffle-admin.js          # Raffle field visibility (53 lines)
├── .gitignore                   # Git ignore patterns
```

### Development Standards
- **Procedural WordPress Pattern**: Direct `require_once` includes for all functionality
- **WordPress Hooks**: Actions and filters for extensibility
- **Modular Design**: Single responsibility principle throughout
- **WordPress Standards**: Full compliance with WordPress coding standards

## 🚀 Quick Start

### Prerequisites
- **WordPress**: 5.0+ multisite network
- **PHP**: 7.4+
- **WooCommerce**: Required for e-commerce functionality
- **ExtraChill Theme**: For optimal integration

### Installation
1. Upload plugin files to `/wp-content/plugins/extrachill-shop/`
2. Activate plugin on shop.extrachill.com
3. Configure WooCommerce settings as needed
4. Ad-free license product is auto-created/maintained (SKU: `ec-ad-free-license`, price: $20)
5. Set shop page as homepage: Settings → Reading → "A static page" → Homepage: "Shop"

### Development Setup
```bash
# Install dependencies
composer install

# Create production build
./build.sh

# Run code quality checks
composer run lint:php
```

## 🔧 Development

### Build Commands
```bash
# Development
composer install                 # Install dependencies
composer run lint:php           # Check code standards
composer run lint:fix           # Fix code style issues

# Production
./build.sh                       # Create deployment package
```

### Build Output
- **Production Package**: `/build/extrachill-shop.zip` file only (unzip when directory access needed)
- **Non-Versioned**: Follows platform standard (no version numbers in filenames)
- **File Exclusion**: Development files excluded via `.buildignore`

## 🛡️ Security Features

- **Input Sanitization**: All user input properly sanitized
- **Output Escaping**: XSS protection for all output
- **Capability Checks**: Proper permission validation
- **Prepared Statements**: SQL injection prevention

## 💳 Ad-Free License System

### User Meta Storage
The plugin uses WordPress-native user meta for license storage (KISS principle, no custom tables):

```php
// Meta key: extrachill_ad_free_purchased
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
5. **Payment Complete**: Order auto-completes (ad-free license only orders)
6. **Order Completion**: Shop plugin calls `ec_create_ad_free_license()` from users plugin
7. **Network-Wide Check**: `is_user_ad_free()` validates license via user meta

### Clean Architecture
- **WordPress-Native Storage**: Uses user meta (accessible network-wide)
- **Username → User ID Mapping**: Handled by `ec_create_ad_free_license()` in extrachill-users plugin
- **Validation Function**: `is_user_ad_free()` in extrachill-users plugin (reads user meta)
- **Creation Function**: `ec_create_ad_free_license()` in extrachill-users plugin (writes user meta)
- **WooCommerce Integration**: Lives in extrachill-shop plugin (product fields, cart, checkout UI)
- **Purchase Handler**: Calls extrachill-users creation function after order completion
- **Clean Separation**: Users plugin owns data operations, shop plugin owns WooCommerce UI only

## 🎟️ Raffle Product System

### Tag-Based Activation
Raffle features only activate when a product has the "raffle" tag:
- Add "raffle" tag to any WooCommerce product to enable raffle functionality
- Admin field and frontend counter appear automatically
- Assets load conditionally only when needed

### Admin Configuration
**Max Raffle Tickets Field** (`inc/products/raffle/admin-fields.php`):
- Appears on WooCommerce Inventory tab when product has "raffle" tag
- Uses MutationObserver to detect tag changes in real-time
- Field visibility controlled by JavaScript (`raffle-admin.js`)
- Saves to `_raffle_max_tickets` post meta

### Frontend Display
**Progress Bar Counter** (`inc/products/raffle/frontend-counter.php`):
- Displays on product pages with "raffle" tag (priority 25 on `woocommerce_single_product_summary`)
- Shows remaining tickets out of max tickets (e.g., "45/100 tickets remaining")
- Calculates percentage: `(remaining / max) * 100`
- Color states based on stock level:
  - **High Stock** (>50%): Green gradient (`--accent` to `--accent-3`)
  - **Medium Stock** (25-50%): Yellow/orange gradient
  - **Low Stock** (<25%): Red gradient with bold text for urgency

### Conditional Asset Loading
**Smart Loading Strategy** (`inc/core/assets.php`):
- **Raffle Frontend CSS**: Only loads on product pages with "raffle" tag (checks `has_term()`)
- **Raffle Admin CSS + JS**: Only loads on product edit screen (`post.php` and `post-new.php` hooks)
- **Cache Busting**: Uses `filemtime()` for automatic version management
- **File Existence Checks**: Verifies files exist before enqueuing

### Responsive Design
**Mobile Optimization**:
- 768px breakpoint: Smaller icon and text, reduced padding
- 480px breakpoint: Further size reduction, minimal spacing
- Dark mode support via `prefers-color-scheme: dark`
- Smooth transitions on progress bar fill

## 🎨 Store Customization

### Breadcrumb System
Integrates with theme's unified breadcrumb system via `extrachill_breadcrumbs_override_trail` filter:
```
Extra Chill › Merch Store › Product Category › Product Name
```
- Uses same architecture as bbPress community integration
- Supports hierarchical product categories
- Context-aware for shop, product, cart, checkout, account pages
- Single source of truth matching platform standards

### Cart Icon
Simple cart icon in site header linking to shop page:
```php
// Hooks into extrachill_header_top_right at priority 25
// Uses FontAwesome SVG from theme
```

## 🧪 Testing

### Manual Testing

#### Ad-Free License System
1. **Product Purchase Flow**: Complete ad-free license purchase
2. **Username Validation**: Test cart and checkout username fields
3. **Cross-Domain Validation**: Verify ad removal on main site via `is_user_ad_free()`
4. **User Authentication**: Test multisite login persistence

#### Raffle Product System
1. **Tag Addition**: Add "raffle" tag to product, verify admin field appears
2. **Tag Removal**: Remove "raffle" tag, verify admin field hides
3. **Max Tickets Configuration**: Set max tickets value, verify saves correctly
4. **Progress Bar Display**: Verify progress bar shows on raffle products
5. **Color States**: Test high/medium/low stock color transitions
6. **Asset Loading**: Verify raffle CSS only loads on raffle products
7. **Admin Assets**: Verify admin CSS + JS only load on product edit screen

#### Store Customization
1. **Breadcrumbs**: Check breadcrumb display on all WooCommerce pages
2. **Cart Icon**: Verify cart icon in header links to shop
3. **Styling**: Check WooCommerce pages render correctly
5. **Responsive Design**: Test all breakpoints (768px, 600px, 480px)
6. **Dark Mode**: Verify dark mode styling works correctly

### Code Quality
```bash
# PHP CodeSniffer
composer run lint:php

# Fix coding standards
composer run lint:fix

# Future: PHPUnit tests
composer run test
```

## 📦 Deployment

### Build Process
The `build.sh` script creates production-ready packages:
- **Version Extraction**: Auto-reads from plugin header
- **File Exclusion**: Removes development files via `.buildignore`
- **Dependency Management**: Production-only composer install
- **ZIP Creation**: Package in `/build` directory

### Production Checklist
- [ ] Run `composer run lint:php`
- [ ] Execute `./build.sh`
- [ ] Test in staging environment
- [ ] Verify cross-domain functionality
- [ ] Deploy to production

## 🔗 Integration Points

### Main Site (extrachill.com)
- **Ad-Free Checks**: Validate user license status via extrachill-users plugin
- **User Authentication**: WordPress multisite login persistence
- **Performance**: Cached license lookups

### Community Site (community.extrachill.com)
- **Username Validation**: Community user verification
- **Profile Integration**: License status in user profiles

### Shop Site (shop.extrachill.com)
- **WooCommerce Integration**: Full e-commerce functionality
- **Custom Templates**: ExtraChill-themed store experience
- **License Products**: Ad-free license and merchandise

## 🤝 Contributing

### Development Standards
- **WordPress Coding Standards**: Strict adherence to WPCS
- **Procedural Pattern**: Direct includes, no PSR-4 autoloading
- **Security First**: Input sanitization and output escaping
- **Performance Focus**: Conditional loading and optimization

### Code Review Checklist
- [ ] WordPress coding standards compliance
- [ ] Security review (sanitization, escaping, capabilities)
- [ ] Cross-domain functionality testing
- [ ] WooCommerce compatibility verification

## 📄 License

GPL v2 or later - Compatible with WordPress and WooCommerce licensing.

## 👤 Author

**Chris Huber**
- Website: [chubes.net](https://chubes.net)
- GitHub: [@chubes4](https://github.com/chubes4)
- Extra Chill: [extrachill.com](https://extrachill.com)

## 🔗 Links

- **Shop**: [shop.extrachill.com](https://shop.extrachill.com)
- **Community**: [community.extrachill.com](https://community.extrachill.com)
- **Main Site**: [extrachill.com](https://extrachill.com)

---

*Part of the ExtraChill Platform - A comprehensive WordPress multisite ecosystem for music community, content management, and e-commerce.*
