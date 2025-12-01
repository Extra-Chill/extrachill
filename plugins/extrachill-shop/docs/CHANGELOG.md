# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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