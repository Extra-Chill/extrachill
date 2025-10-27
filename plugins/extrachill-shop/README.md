# Extra Chill Shop

WordPress plugin providing WooCommerce integration and e-commerce functionality for the Extra Chill platform. Features cross-domain ad-free license system, custom breadcrumbs, product category navigation, and comprehensive WooCommerce styling.

## 🛒 Overview

The Extra Chill Shop plugin extends WooCommerce with ExtraChill-specific functionality, including a unique cross-domain ad-free license system that allows purchases on `shop.extrachill.com` to disable ads on `extrachill.com`.

## ✨ Key Features

### 🔐 Cross-Domain Ad-Free License System
- **Multi-Domain Integration**: Purchases on shop site affect ad display on main site
- **WordPress Multisite**: Native multisite authentication for seamless cross-domain user sessions
- **License Management**: Automated license activation and validation
- **Community Integration**: Links purchases to community usernames

### 🎨 Store Customization
- **Unified Breadcrumbs**: Integrates with theme's breadcrumb system via filter for consistent "Extra Chill › Merch Store" structure
- **Product Category Header**: Dynamic secondary navigation with product categories
- **Cart Icon Integration**: Header cart icon linking to shop
- **Comprehensive Styling**: 590 lines of WooCommerce CSS with responsive design

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
│   └── core/                    # Core plugin files
│       ├── ad-free-license.php      # License purchase processing
│       ├── assets.php               # Asset enqueuing
│       ├── breadcrumb-integration.php # Breadcrumb customization
│       └── database.php             # Database table creation
├── templates/                   # Template files
│   ├── archive-product.php          # Product archive template
│   ├── cart-icon.php                # Header cart icon
│   ├── content-single-product.php   # Product content
│   ├── product-category-header.php  # Category navigation
│   └── single-product.php           # Single product template
├── assets/                      # CSS/JS assets
│   └── css/
│       └── woocommerce.css      # WooCommerce styling
└── languages/                   # Translation files
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
4. Set up ad-free license product (ID: 90123)
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
- **Production Package**: `/build/extrachill-shop/` directory and `/build/extrachill-shop.zip` file
- **Non-Versioned**: Follows platform standard (no version numbers in filenames)
- **File Exclusion**: Development files excluded via `.buildignore`

## 🛡️ Security Features

- **Input Sanitization**: All user input properly sanitized
- **Output Escaping**: XSS protection for all output
- **Capability Checks**: Proper permission validation
- **Prepared Statements**: SQL injection prevention

## 💳 Ad-Free License System

### Database Schema
```sql
extrachill_ad_free:
├── id (int)                     # Primary key
├── username (varchar)           # Community username (unique)
├── date_purchased (datetime)    # Purchase timestamp
└── order_id (int)              # WooCommerce order ID
```

### Integration Flow
1. **Product Page**: User enters community username
2. **Cart Process**: Username validation and storage
3. **Order Completion**: License record creation
4. **Cross-Domain Check**: Ad-free status verification

### WordPress Multisite Integration
- **Native Authentication**: WordPress multisite handles cross-domain sessions
- **User Validation**: Direct user lookup across multisite network
- **Performance**: Hardcoded blog IDs for optimization
- **Security**: WordPress capability system for access control

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

### Product Category Header
Dynamic secondary navigation showing product categories ordered by popularity:
```php
// Automatically displays on WooCommerce pages
// Categories ordered by product count (DESC)
// Hooks into extrachill_after_header
```

### Cart Icon
Simple cart icon in site header linking to shop page:
```php
// Hooks into extrachill_header_top_right at priority 25
// Uses FontAwesome SVG from theme
```

## 🧪 Testing

### Manual Testing
1. **Product Purchase Flow**: Complete ad-free license purchase
2. **Cross-Domain Validation**: Verify ad removal on main site
3. **User Authentication**: Test multisite login persistence
4. **Styling**: Check WooCommerce pages render correctly

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
