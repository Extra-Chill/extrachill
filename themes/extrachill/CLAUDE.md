# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## KNOWN ISSUES

- Comment system needs review, particularly community user data integration and author links
- Instagram integration feature needs completion for direct posting from wp-admin
- Remote login system needs registration functionality added

## FUTURE PLANS

- Complete event import system refactor with Action Scheduler implementation
- Add homepage extrachill.link feature section
- Gradual theme renaming and final ColorMag cleanup
- Co-Authors Plus and WP-PageNavi plugin migrations

## Project Overview

The ExtraChill theme is a custom WordPress theme serving as the frontend for an independent music ecosystem that includes a blog, a forum (extrachill-community theme), and a merch store (extrachill-shop theme). The theme powers extrachill.com with custom music event listings, festival coverage, community integration, and journalistic written content about the music industry.

**Current Status**: Fully converted from ColorMag Pro with performance optimizations and modern WordPress features.

## Architecture & Core Components

### Modular File Structure
The theme uses a clean, modular architecture organized in the `/inc/` directory:
- **admin/**: Administrative functionality (customizer, contact forms, logging)
- **community/**: Forum integration, user sync, upvotes, activity feeds
- **core/**: Essential WordPress functionality (breadcrumbs, taxonomies, SEO)
- **home/**: Homepage-specific components and sections (includes Festival Wire homepage ticker)
- **woocommerce/**: E-commerce integration with performance optimization

### Custom Post Types & Taxonomies
- **Custom Taxonomies**: Artist, Venue, Festival, Location taxonomies with REST API support
- **Chill Events**: Event scraping system (`chill-events/data-sources/scrapers/`)
- **Festival Wire Integration**: Homepage ticker display for Festival Wire posts (handled by ExtraChill News Wire plugin)

### Community Integration
- **bbPress Forum Integration**: Community comments, upvotes, activity feeds (`inc/community/`)
- **User Session Management**: Custom authentication and user sync
- **Forum Search**: Custom search functionality for community content
- **Activity Feed**: Recent community activity integration

### WooCommerce Integration
- **Performance Optimized**: WooCommerce only loads when products are present or on store pages
- **Conditional Theme Support**: Dynamic theme support based on context
- **Safe Wrapper Functions**: Helper functions prevent WooCommerce errors
- **Custom Templates**: Product pages and cart integration (`inc/woocommerce/`, `woocommerce/`)
- **Asset Optimization**: Prevents unnecessary script/style loading

### CSS Architecture
- **Root Variables**: Global CSS custom properties in `css/root.css`
- **Modular Loading**: Page-specific CSS files (`home.css`, `archive.css`, `single-post.css`)
- **Component Styles**: Separate files for badges, navigation, WooCommerce integration
- **Dark Mode Ready**: CSS custom properties support automatic theme switching
- **Performance Loading**: Conditional CSS enqueuing based on page context
- **Dependency Management**: Proper CSS loading order with dependencies

## Development Commands

There are no build tools configured. The theme uses:
- Direct CSS/JS file editing
- WordPress native asset enqueuing with `filemtime()` versioning for cache busting
- Manual minification when needed
- Modern WordPress hooks and filters for extensibility

Common development tasks:
```bash
# Check for PHP syntax errors
php -l filename.php

# WordPress debugging (add to wp-config.php):
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('SCRIPT_DEBUG', true);

# Check theme functionality
wp theme status extrachill

# Flush rewrite rules after taxonomy changes
wp rewrite flush
```

## Key File Locations

### Core Theme Files
- **`functions.php`** - Main theme setup, asset loading, and module includes
- **`inc/functions.php`** - Core helper functions, meta display, and comment system
- **`inc/woocommerce/core.php`** - WooCommerce integration with performance optimization
- **`style.css`** - Main stylesheet with CSS reset and core styles
- **`css/root.css`** - CSS custom properties and theme variables

### Asset Loading Strategy
- **Dynamic CSS Loading**: Page-specific stylesheets loaded conditionally
- **JavaScript Optimization**: Scripts enqueued only when needed
- **Font Management**: Web fonts served from local `fonts/` directory
- **Cache Busting**: `filemtime()` versioning for all assets
- **Priority Loading**: Root CSS loads first, other styles depend on it

### Template Hierarchy
- **Taxonomy Templates**: Custom templates for artist, venue, festival, location
- **WooCommerce**: Custom product templates with conditional loading
- **Page Templates**: Specialized templates in `page-templates/` directory
- **Festival Wire**: Post type templates handled by ExtraChill News Wire plugin

## Custom Functionality

### Event Scraping System
- **Multi-venue Support**: Scrapers for Charleston Tin Roof, Forte Jazz Lounge, The Burgundy Lounge, The Commodore, The Royal American
- **File Location**: `chill-events/data-sources/scrapers/`
- **Execution**: Manual execution via admin interface or scheduled jobs
- **Data Structure**: Standardized event data format across all scrapers

### Newsletter System
- **Plugin Integration**: Newsletter functionality provided by ExtraChill Newsletter Plugin
- **Homepage Integration**: Plugin hooks into theme via `do_action('extrachill_homepage_newsletter_section')`
- **Template Management**: All newsletter templates handled by plugin, not theme

### Festival Wire Integration
- **Homepage Ticker**: Dynamic ticker display of latest Festival Wire posts
- **Plugin Integration**: Festival Wire functionality provided by ExtraChill News Wire plugin
- **Theme Support**: Homepage ticker component maintains display functionality
- **File Location**: Homepage ticker component in `inc/home/festival-wire-ticker.php`

### Performance Features
- **WooCommerce Optimization**: Conditional loading prevents global initialization
- **Asset Optimization**: CSS/JS loaded only when needed
- **Memory Management**: Memory usage tracking and optimization
- **Image Optimization**: Unnecessary WordPress image sizes removed
- **Query Optimization**: Efficient taxonomy and post queries

## Security & Best Practices

- **Output Escaping**: All output escaped via WordPress functions (`esc_html()`, `esc_attr()`, `esc_url()`)
- **Input Sanitization**: All inputs sanitized with `wp_unslash()` and appropriate sanitization functions
- **Nonce Verification**: AJAX requests protected with nonce verification
- **Capability Checks**: Admin functions protected with proper capability checks
- **No Hardcoded Secrets**: No API keys or credentials in codebase
- **External Links**: Automatic `target="_blank"` and `rel="noopener noreferrer"` for security

## Theme Constants

```php
EXTRACHILL_PARENT_DIR - Theme root directory path
EXTRACHILL_INCLUDES_DIR - Inc directory path for modular includes
```

## WordPress Standards Compliance

- **PSR-4 Namespacing**: Clean, organized code structure
- **WordPress Hooks**: Extensive use of filters for extensibility
- **Translation Ready**: All strings properly internationalized
- **Accessibility**: Semantic HTML and ARIA attributes
- **REST API**: Custom taxonomies support REST API integration

## Important Notes

- **Community-Focused**: Deep integration with bbPress and custom user systems
- **Performance Optimized**: WooCommerce conditional loading prevents unnecessary resource usage
- **Modern Architecture**: Clean separation of concerns with modular file structure
- **No Build Process**: Direct file editing with WordPress native optimization
- **Extensible Design**: Filter-based architecture supports easy customization