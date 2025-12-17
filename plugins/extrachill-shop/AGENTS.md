# Extra Chill Shop - Agent Development Guide

## Overview
WooCommerce integration with ad-free license system for shop.extrachill.com (Blog ID 3). Provides e-commerce functionality with cross-domain license validation and custom product features.

## WooCommerce Integration
- Homepage override using `extrachill_template_homepage` filter
- Template system combining theme filters with WooCommerce template overrides
- Comprehensive WooCommerce styling with responsive design
- Cart icon integration in site header
- Breadcrumb customization using theme's breadcrumb system

## Ad-Free License Validation
- Cross-domain license system using user meta storage
- Ad-free product auto-provisioned via SKU (`ec-ad-free-license`)
- Purchase processing via WooCommerce order completion hooks
- Username validation throughout checkout flow
- Auto-completion for ad-free license orders
- Integration with `is_user_ad_free()` function from extrachill-users plugin

## E-commerce Features
- Raffle product system with progress counters and stock visualization
- Product category navigation and grid layouts
- Custom product fields for community usernames
- Cart validation and checkout customization
- Network-wide license availability

## File Organization
```
extrachill-shop/
├── extrachill-shop.php          # Main plugin file
├── inc/
│   ├── core/
│   │   ├── assets.php           # Asset management
│   │   ├── breadcrumb-integration.php # Breadcrumb customization
│   │   └── woocommerce-templates.php # Template filters
│   ├── products/
│   │   ├── ad-free-license.php  # License system
│   │   └── raffle/              # Raffle features
│   └── templates/
│       ├── shop-homepage.php    # Homepage template
│       └── cart-icon.php        # Header cart icon
├── woocommerce/                 # WooCommerce template overrides
├── assets/                      # CSS/JS assets
└── docs/                        # Documentation
```

## Dependencies
- WooCommerce plugin
- extrachill theme (for template integration)
- extrachill-users plugin (for license validation)
- WordPress multisite network