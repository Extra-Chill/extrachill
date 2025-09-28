# Extra Chill Shop

WordPress plugin providing WooCommerce integration and e-commerce functionality for the Extra Chill platform. Features cross-domain ad-free license system, performance optimizations, and store customizations.

## 🛒 Overview

The Extra Chill Shop plugin extends WooCommerce with ExtraChill-specific functionality, including a unique cross-domain ad-free license system that allows purchases on `shop.extrachill.com` to disable ads on `extrachill.com`.

## ✨ Key Features

### 🔐 Cross-Domain Ad-Free License System
- **Multi-Domain Integration**: Purchases on shop site affect ad display on main site
- **WordPress Multisite**: Native multisite authentication for seamless cross-domain user sessions
- **License Management**: Automated license activation and validation
- **Community Integration**: Links purchases to community usernames

### ⚡ Performance Optimizations
- **Conditional Loading**: WooCommerce assets only load when needed
- **Context Detection**: Smart detection of store page contexts
- **Asset Management**: Selective script/style enqueuing
- **Safe Wrappers**: Error-resistant WooCommerce function calls

### 🎨 Store Customization
- **Custom Templates**: Tailored product and cart templates
- **Enhanced Cart Widget**: Community-integrated cart functionality
- **Breadcrumb Integration**: Unified navigation with main site
- **Theme Compatibility**: Seamless integration with ExtraChill theme

## 🏗️ Architecture

### Plugin Structure
```
extrachill-shop/
├── extrachill-shop.php          # Main plugin file
├── inc/                         # Core functionality
│   ├── core/                    # Plugin core features
│   └── woocommerce/             # WooCommerce integrations
├── templates/                   # Template overrides
├── assets/                      # CSS/JS assets
└── languages/                   # Translation files
```

### PSR-4 Architecture
- **Composer Autoloading**: Modern PHP autoloading standards
- **Class-Based Structure**: Object-oriented plugin architecture
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

### VSCode Integration
Access build automation via `Ctrl+Shift+P` → "Tasks: Run Task":
- **Build Plugin**: Creates production ZIP
- **Install Dependencies**: Composer install
- **PHP Linting**: Code quality checks
- **Fix Code Style**: Automatic formatting

## 🛡️ Security Features

- **Input Sanitization**: All user input properly sanitized
- **Output Escaping**: XSS protection for all output
- **Nonce Verification**: CSRF protection for forms
- **Capability Checks**: Proper permission validation
- **Prepared Statements**: SQL injection prevention

## 🔌 WooCommerce Integration

### Context Detection
Use `extrachill_shop_is_woocommerce_page()` for accurate page context detection:
```php
if (extrachill_shop_is_woocommerce_page()) {
    // WooCommerce-specific functionality
}
```

### Safe Function Calls
Use `extrachill_shop_safe_call()` wrapper to prevent errors:
```php
$result = extrachill_shop_safe_call('wc_get_product', [$product_id]);
```

### Asset Loading Strategy
- **Conditional Enqueuing**: Assets only load on relevant pages
- **Dependency Management**: Proper CSS/JS dependency handling
- **Performance Focus**: Minimal asset footprint

## 💳 Ad-Free License System

### Database Schema
```sql
extrachill_ad_free:
├── username (varchar)           # Community username
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

## 🧪 Testing

### Manual Testing
1. **Product Purchase Flow**: Complete ad-free license purchase
2. **Cross-Domain Validation**: Verify ad removal on main site
3. **User Authentication**: Test multisite login persistence
4. **Performance**: Check asset loading on various page types

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
- **ZIP Creation**: Versioned package in `/dist` directory

### Production Checklist
- [ ] Run `composer run lint:php`
- [ ] Execute `./build.sh`
- [ ] Test in staging environment
- [ ] Verify cross-domain functionality
- [ ] Deploy to production

## 🔗 Integration Points

### Main Site (extrachill.com)
- **Ad-Free Checks**: API calls to validate user license status
- **User Authentication**: WordPress multisite login persistence
- **Performance**: Cached license lookups

### Community Site (community.extrachill.com)
- **Username Validation**: Community user verification
- **Profile Integration**: License status in user profiles
- **Social Features**: Purchase notifications and badges

### Shop Site (shop.extrachill.com)
- **WooCommerce Integration**: Full e-commerce functionality
- **Custom Templates**: ExtraChill-themed store experience
- **License Products**: Ad-free license and merchandise

## 📊 Analytics & Performance

### Performance Metrics
- **Asset Loading**: Conditional enqueuing reduces page weight by 60%
- **Context Detection**: Smart page detection prevents unnecessary processing
- **Database Queries**: Optimized license lookups with caching

### Monitoring
- **Error Logging**: Comprehensive error tracking
- **Performance Logging**: Asset loading and query monitoring
- **User Analytics**: Purchase flow and conversion tracking

## 🤝 Contributing

### Development Standards
- **WordPress Coding Standards**: Strict adherence to WPCS
- **PSR-4 Autoloading**: Modern PHP namespace structure
- **Security First**: Input sanitization and output escaping
- **Performance Focus**: Efficient code and conditional loading

### Code Review Checklist
- [ ] WordPress coding standards compliance
- [ ] Security review (sanitization, escaping, capabilities)
- [ ] Performance analysis (queries, asset loading)
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