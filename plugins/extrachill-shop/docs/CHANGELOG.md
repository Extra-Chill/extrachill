# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] - 2025-12-16

### Removed
- **Artist Dashboard System**: Complete removal of artist self-management functionality (endpoints, product forms, order management, settings, CSS)
- **Artist Dashboard CSS**: Removed comprehensive dashboard styling (377 lines)

### Added
- **Stripe Connect Payment Integration**: Automatic destination charges for artist payouts with commission handling
- **Product Gallery JavaScript**: Interactive image gallery with thumbnail navigation on single product pages
- **Artist Storefront Manage Button**: "Manage Shop" CTA for artists on their storefront archives
- **Ad-Free License Auto-Provisioning**: Automatic maintenance of license product using SKU `ec-ad-free-license`
- **WooCommerce Template Overrides**: Custom cart and add-to-cart templates with enhanced styling
- **Button Classes Filter**: Consistent button styling across WooCommerce elements
- **E-commerce Integration Documentation**: Comprehensive docs for payment and license systems

### Changed
- **AGENTS.md**: Simplified documentation structure and updated feature overview
- **README.md**: Updated setup instructions for auto-provisioned license product
- **WooCommerce CSS**: Complete overhaul using theme CSS variables, responsive design, and dark mode support
- **Raffle CSS**: Updated font sizes to use theme CSS variables for consistency
- **Stripe Integration**: Enhanced webhook handling and account management functions
- **Core Assets**: Added conditional loading for product gallery JavaScript

### Technical Details
- Removed 6 artist dashboard files (endpoints, orders, product-form, products-list, settings, CSS)
- Added 9 new files for enhanced e-commerce functionality
- Updated WooCommerce styling from 49 to 176+ lines with theme integration
- Enhanced Stripe Connect for production-ready artist payouts
- Improved responsive design across all WooCommerce components

## [0.2.0] - 2025-12-08

### Added
- **Artist Marketplace System**: Complete artist marketplace with taxonomy integration, cross-site artist profile lookups, and artist store URLs
- **Stripe Connect Integration**: Full Stripe Express account management, onboarding flows, webhook handling, and payment processing (**⚠️ NOT YET TESTED**)
- **Artist Dashboard**: My Account endpoints for artists to manage products, orders, and Stripe settings
- **Commission System**: Platform commission rate configuration with per-product overrides and WooCommerce settings integration
- **Navigation**: Product category navigation functionality
- **Dependencies**: Added Stripe PHP SDK (^13.0)

### Changed
- **Homepage Template**: Updated to use action hook instead of filter for better theme integration
- **Breadcrumbs**: Labels updated from "Merch Store" to "Shop" with dynamic blog ID lookups
- **Asset Loading**: Added conditional CSS loading for artist dashboard

### Technical Details
- Added 8 new include files for marketplace functionality
- Cross-site artist integration with multisite support
- WooCommerce settings integration for commission rate management
- Artist dashboard CSS asset loading on account pages
- Dynamic blog ID lookups using ec_get_blog_id() function

## [0.1.0] - 2025-12-01

### Added
- **Cross-Domain Ad-Free License System**: Complete WooCommerce integration for ad-free license purchases with username validation, cart/checkout flow, and auto-completion
- **Raffle Product System**: Tag-based activation with admin fields for max tickets, frontend progress counter with color-coded states, and conditional asset loading
- **Breadcrumb Integration**: Theme-integrated breadcrumbs with "Extra Chill › Merch Store" structure and context-aware trails
- **Cart Icon**: Header cart icon integration with FontAwesome SVG
- **WooCommerce Styling**: Comprehensive CSS styling (492 lines) with responsive design, dark mode support, and theme integration
- **Asset Management**: Conditional loading system for WooCommerce CSS, raffle assets, and cache busting
- **Hybrid Template System**: Homepage override with WooCommerce template filters for single products, cart, and checkout
- **Plugin Architecture**: Singleton pattern with modular includes, WordPress hooks, and security best practices

### Technical Details
- WordPress-native storage using user meta (no custom tables)
- Conditional asset loading (only loads when needed)
- Responsive design with mobile breakpoints (768px, 600px, 480px)
- Theme integration via filters and action hooks
- Security: Input sanitization, output escaping, prepared statements
- Multisite-compatible cross-domain functionality