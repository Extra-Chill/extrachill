# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The **Extra Chill Shop** plugin provides WooCommerce integration and e-commerce functionality for the Extra Chill platform. This plugin extracts all WooCommerce functionality from the main theme to improve performance and maintain proper separation of concerns.

## Key Features

### Cross-Domain Ad-Free License System
- **Purpose**: Purchases made on `shop.extrachill.com` disable ads on `extrachill.com`
- **Integration**: Links with WordPress multisite native authentication system
- **Database**: Uses `extrachill_ad_free` table to track licenses across multisite network
- **Product ID**: 90123 (hardcoded ad-free license product)
- **Authentication**: Uses native WordPress multisite authentication for cross-domain user sessions

### WooCommerce Performance Optimization
- **Conditional Loading**: WooCommerce assets only load on store pages
- **Context Detection**: Smart detection of WooCommerce page contexts
- **Asset Management**: Selective script/style enqueuing for performance
- **Safe Wrappers**: Error-resistant function calls for WooCommerce integration

### Store Customization
- **Template Overrides**: Custom product and cart templates
- **Cart Widget**: Enhanced cart functionality with community integration
- **Breadcrumb Integration**: Store navigation integrated with main site
- **Secondary Headers**: Custom store header functionality

## Architecture Standards

### Plugin Structure
```
extrachill-shop/
├── extrachill-shop.php          # Main plugin file
├── inc/                         # Modular functionality
│   ├── core/                    # Core plugin functionality
│   └── woocommerce/             # WooCommerce-specific features
├── templates/                   # Template overrides
├── assets/                      # CSS/JS assets
│   ├── css/
│   └── js/
└── languages/                   # Translation files
```

### Development Standards
- **PSR-4 Autoloading**: Follow composer autoloading standards
- **WordPress Hooks**: Extensive use of actions/filters for extensibility
- **Security First**: All inputs sanitized, outputs escaped, nonces verified
- **Performance Focused**: Conditional loading, caching, optimization
- **Modular Design**: Single responsibility principle for all files

### Cross-Domain Integration
- **WordPress Multisite**: Native WordPress multisite provides unified authentication across domains
- **Database Sharing**: Shared `extrachill_ad_free` table across WordPress multisite network
- **Authentication**: Community username validation using native WordPress multisite user system
- **API Integration**: REST endpoints maintained for legacy compatibility during multisite migration
- **Authentication Integration**: WordPress multisite native authentication provides seamless cross-domain user sessions

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

### VSCode Integration
Use `Ctrl+Shift+P` → "Tasks: Run Task" to access build automation:
- **Build Plugin**: Creates production ZIP package
- **Install Dependencies**: Runs composer install
- **Run PHP Linting**: Code quality checks
- **Fix PHP Code Style**: Automatic code formatting

## WooCommerce Integration Guidelines

### Context Detection
Always use `extrachill_shop_is_woocommerce_page()` for page context detection rather than direct WooCommerce functions.

### Safe Function Calls
Use `extrachill_shop_safe_call()` wrapper for all WooCommerce function calls to prevent errors when WooCommerce is inactive.

### Asset Loading
- Load WooCommerce assets only on relevant pages
- Use `extrachill_shop_is_woocommerce_page()` for conditional enqueuing
- Implement proper dependency management for CSS/JS

### Template System
- Use plugin-based template override system
- Maintain compatibility with main theme styling
- Follow WordPress template hierarchy standards

## Ad-Free License System

### Database Structure
```sql
extrachill_ad_free:
- username (varchar) - Community username
- date_purchased (datetime) - Purchase timestamp
- order_id (int) - WooCommerce order ID
```

### Integration Points
- **Product Page**: Community username field (Product ID: 90123)
- **Cart Process**: Username validation and metadata storage
- **Order Completion**: Database record creation
- **Cross-Domain Check**: API endpoint for ad-free status verification

### Security Considerations
- Username sanitization with `sanitize_text_field()`
- Native WordPress multisite user authentication for cross-domain sessions
- Prepared SQL statements for all database operations
- Capability checks for administrative functions
- **Integration**: Direct WordPress multisite authentication eliminates need for custom session management

## Plugin Dependencies

### Required
- **WordPress**: 5.0+
- **PHP**: 7.4+
- **WooCommerce**: Plugin will deactivate if WooCommerce is not active

### Development
- **Composer**: Dependency management and autoloading
- **PHP CodeSniffer**: Code quality and WordPress standards compliance
- **PHPUnit**: Unit testing framework (when implemented)

## Deployment Workflow

1. **Development**: Direct file editing with live WordPress environment
2. **Quality Assurance**: Run composer lint and test commands
3. **Build**: Execute `./build.sh` to create production package
4. **Deploy**: Upload generated ZIP to WordPress admin or via deployment pipeline

## Integration with Main Theme

The plugin is designed to work with the main `extrachill` theme across all domains:
- **shop.extrachill.com**: Uses main theme + this plugin
- **extrachill.com**: Uses main theme + checks ad-free status via this plugin's API
- **Shared Assets**: WordPress multisite native authentication and user management

## Future Development

### Planned Features
- Enhanced analytics integration
- Additional payment gateway support
- Advanced product customization options
- Mobile app API endpoints

### Migration Notes
This plugin extracts functionality previously embedded in the main theme. During migration:
1. Preserve all existing ad-free license functionality
2. Maintain cross-domain authentication compatibility
3. Ensure performance optimizations are retained
4. Test thoroughly across all Extra Chill domains