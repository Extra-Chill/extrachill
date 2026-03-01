# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.6.1] - 2026-03-01

### Fixed
- Update event post type references from `datamachine_events` to `data_machine_events` in priority-boost to match upstream rename

## [0.6.0] - 2026-01-26

### Changed
- Add Event Priority Boost product
- Version bump to 0.5.3 - Shipping and schema breadcrumbs
- Remove vendor directory from git tracking
- Test message 2
- Version bump to 0.5.2 - Shipping free items + schema breadcrumbs
- Version bump to 0.5.1 - Rename Ad-Free License to Lifetime Membership
- Version bump to 0.5.0 - Shipping + Stripe transfer payouts
- Version bump to 0.4.1 - Enhanced shop integration and refactoring
- Version bump to 0.4.0 - Artist Order Notifications & Shop Enhancements
- Version bump to 0.3.0 - Artist Dashboard Removal & Stripe Payout Integration
- Version bump to 0.2.0 - Artist Marketplace & Stripe Connect
- Initial release 0.1.0
- getting this bitch up and running
- basic integration of woocommerce system
- Update shop integration and clean build files
- Update shop plugin templates and functionality
- Documentation alignment: Fix PSR-4 autoloading claims
- moved ad free license logic into this plugin out of theme.
- Initial commit: WordPress plugin conversion from theme

## [0.5.2] - 2026-01-06

### Added
- **Schema Breadcrumb Overrides for Shop**: Added `extrachill_shop_schema_breadcrumb_items()` filter handler to align SEO/schema breadcrumbs with the shop’s visual breadcrumb structure on `shop.extrachill.com` (homepage, product categories with ancestors, single products, cart/checkout/account).
- **Free-Shipping Cart Logic**: Added `extrachill_shop_cart_ships_free()` and updated the custom WooCommerce shipping method to return a `Free Shipping` rate when all cart items have `_ships_free` meta set.

### Changed
- **Single Product Layout**: Displays taxonomy badges in the product summary when `extrachill_display_taxonomy_badges()` is available.
- **Product Admin UX**: Artist meta box now shows a tip for using `EC_PLATFORM_ARTIST_ID` when the constant is defined.
- **WooCommerce CSS**: Adds spacing for `.product-summary .taxonomy-badges`.

## [0.5.1] - 2026-01-03

### Changed
- **Renamed Ad-Free License System to Lifetime Extra Chill Membership**: Updated all internal terminology, file names, and logic to reflect the new membership branding (ad-free remains the core benefit).
- **Documentation Refinement**: Comprehensive updates to README and CLAUDE.md detailing the Stripe Connect payout pattern and Shippo shipping label integration.

## [0.5.0] - 2026-01-02

### Added
- Shipping system integration (settings, Shippo client, and checkout shipping flow)
- Cross-site helper `extrachill_shop_get_product_count_for_user()` for aggregating product counts across a user’s artists

### Changed
- Stripe Connect payout flow refactored to use Stripe “Separate Charges and Transfers” (creates Transfers post-payment instead of separate PaymentIntents)
- Stripe key + webhook secret sourcing updated to use Network Admin (site options) and filters for overrides
- Artist storefront “Manage Shop” button restricted to admins (development-only gate)
- Lifetime membership product auto-provisioning now links the product to the platform artist for shop manager visibility
- Artist product meta save: ignore empty artist profile selections to avoid accidental overwrites

## [0.4.1] - 2025-12-18

### Added
- Custom WooCommerce archive template with filter bar integration for product taxonomy pages
- Enhanced archive header with actions slot for manage buttons on artist storefronts
- Filter bar display on product category and taxonomy archive pages

### Changed
- Refactored artist profile lookup to use centralized `ec_get_artist_profile_by_slug()` function
- Improved artist storefront manage button integration with theme hooks
- Removed default WooCommerce result count and ordering on archives (replaced with filter bar)
- Simplified artist manage URL construction

### Technical Details
- Added custom `woocommerce/archive-product.php` template
- Added custom `woocommerce/loop/header.php` for archive headers
- Enhanced template loader to handle product taxonomy archives

## [0.4.0] - 2025-12-17

### Added
- **Artist Order Notifications**: Automated email system notifying artists when their products are ordered, with detailed HTML emails including order items, payouts, and shipping information
- **Shop Filter Bar**: Universal filter integration with artist taxonomy dropdown, sort options (price, popularity, date), and search functionality with preserved pagination parameters
- **Artist Taxonomy Badges**: Visual artist attribution on product cards with clickable links to artist archives

### Changed
- **Homepage Template**: Enhanced with filter bar integration and advanced WP_Query support for artist filtering and multiple sort options
- **WooCommerce Styling**: Complete CSS modernization using theme CSS variables, responsive design improvements, and placeholder image styling

## [0.3.0] - 2025-12-16

### Removed
- **Artist Dashboard System**: Complete removal of artist self-management functionality (endpoints, product forms, order management, settings, CSS)
- **Artist Dashboard CSS**: Removed comprehensive dashboard styling (377 lines)

### Added
- **Stripe Connect Payment Integration**: Automatic destination charges for artist payouts with commission handling
- **Product Gallery JavaScript**: Interactive image gallery with thumbnail navigation on single product pages
- **Artist Storefront Manage Button**: "Manage Shop" CTA for artists on their storefront archives
- **Lifetime Extra Chill Membership Auto-Provisioning**: Automatic maintenance of membership product using SKU `ec-lifetime-membership` (ad-free)
- **WooCommerce Template Overrides**: Custom cart and add-to-cart templates with enhanced styling
- **Button Classes Filter**: Consistent button styling across WooCommerce elements
- **E-commerce Integration Documentation**: Comprehensive docs for payment and license systems

### Changed
- **CLAUDE.md**: Simplified documentation structure and updated feature overview
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
- **Cross-Domain Lifetime Extra Chill Membership System**: Complete WooCommerce integration for membership purchases with username validation, cart/checkout flow, and auto-completion (ad-free)
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
