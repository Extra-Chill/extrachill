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
- **admin/**: Administrative functionality (customizer, logging, tag migration)
- **archives/**: Archive page functionality (custom sorting, child terms dropdown)
- **community/**: Forum integration, user sync, upvotes, activity feeds
- **core/**: Essential WordPress functionality with shared templates:
  - **core/templates/**: Shared template components (post-meta, share, social-links)
  - **core/editor/**: Custom embeds (Bandcamp, Instagram, Spotify)
- **header/**: Navigation functionality (walker, menu system)
- **home/**: Homepage-specific components and template sections
- **single-post/**: Single post functionality (comments, related posts)
- **woocommerce/**: E-commerce integration with performance optimization

### Custom Post Types & Taxonomies
- **Custom Taxonomies**: Artist, Venue, Festival, Location taxonomies with REST API support
- **Festival Wire Integration**: Homepage ticker display for Festival Wire posts (handled by ExtraChill News Wire plugin)

### Community Integration
- **bbPress Forum Integration**: Community comments, upvotes, activity feeds (`inc/community/`)
- **WordPress Multisite Integration**: Native WordPress multisite functions replace custom session management
- **Forum Search**: Multisite-native search functionality (`inc/community/multisite-forum-search.php`)
  - `ec_fetch_forum_results_multisite()` replaces REST API calls with direct database queries
  - Uses hardcoded blog ID 2 for maximum performance (no database lookups)
- **Activity Feed**: Native multisite recent activity integration (`inc/community/recent-activity-feed.php`)
  - `ec_fetch_recent_activity_multisite()` uses direct database queries instead of REST API
- **User Details**: Native WordPress authentication (`inc/community/community-session.php`)
  - `preload_user_details()` replaces custom session token validation
  - Uses `is_user_logged_in()` and `wp_get_current_user()` for authentication

### WooCommerce Integration
- **Performance Optimized**: WooCommerce only loads when products are present or on store pages
- **Conditional Theme Support**: Dynamic theme support based on context
- **Safe Wrapper Functions**: Helper functions prevent WooCommerce errors
- **Custom Templates**: Product pages and cart integration (`inc/woocommerce/`, `woocommerce/`)
- **Asset Optimization**: Prevents unnecessary script/style loading

### CSS Architecture
- **Root Variables**: Global CSS custom properties in `assets/css/root.css`
- **Modular Loading**: Page-specific CSS files (`home.css`, `archive.css`, `single-post.css`)
- **Component Styles**: Separate files for badges, navigation, custom lightbox
- **Performance Loading**: Conditional CSS enqueuing based on page context
- **Dependency Management**: Proper CSS loading order with root.css loading first
- **Custom Lightbox**: Gallery-specific lightbox functionality with conditional loading

## Development Commands

### Development Workflow
The theme supports direct file editing for development:
- Direct CSS/JS file editing with no build step required
- WordPress native asset enqueuing with `filemtime()` versioning for cache busting
- Modern WordPress hooks and filters for extensibility

### Production Deployment
For production deployment, use the build script:
```bash
# Create production-ready ZIP file
./build.sh

# Build process includes:
# - File exclusion via .buildignore
# - Production Composer dependencies
# - Structure validation
# - Clean ZIP package creation
```

### Common Development Tasks
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
- **`inc/core/assets.php`** - Centralized asset management with conditional loading
- **`inc/single-post/comments.php`** - Comment system with community integration
- **`inc/core/templates/post-meta.php`** - Post meta display template
- **`style.css`** - Main stylesheet with CSS reset and core styles
- **`assets/css/root.css`** - CSS custom properties and theme variables

### Asset Loading Strategy
- **Centralized Management**: All asset loading handled in `inc/core/assets.php`
- **Dynamic CSS Loading**: Page-specific stylesheets loaded conditionally
- **JavaScript Optimization**: Scripts enqueued only when needed (reading progress, navigation)
- **Cache Busting**: `filemtime()` versioning for all assets
- **Priority Loading**: Root CSS loads first (priority 5), other styles depend on it
- **Navigation Scripts**: Menu navigation JavaScript with conditional loading

### Template Hierarchy
- **Taxonomy Templates**: Custom templates for artist, venue, festival, location
- **WooCommerce**: Custom product templates with conditional loading
- **Page Templates**: Specialized templates in `page-templates/` directory
- **Festival Wire**: Custom post type functionality provided by ExtraChill News Wire plugin

## Custom Functionality

### Removed Systems
- **Newsletter System**: Functionality moved to ExtraChill Newsletter Plugin
- **Contact Form System**: Functionality moved to ExtraChill Contact Plugin
- **Session Token System**: Completely removed in favor of native WordPress multisite authentication
- **Event Submission System**: Completely removed - all JavaScript and server-side functionality deleted
  - Deleted: `assets/js/event-submission-logged-out.js`
  - Deleted: `assets/js/event-submission-modal.js`
  - All event submission modal and logged-out user handling removed
- **Location Filter Client-Side System**: Frontend JavaScript functionality completely removed
  - Deleted: `assets/js/location-filter.js`
  - Added: `inc/location-filter.php` (server-side only, NOT included in functions.php)
  - Backend AJAX handlers remain functional but dormant (not actively loaded)
  - Frontend location filtering interface completely removed

### Plugin Integration Points
- **Festival Wire**: ExtraChill News Wire plugin hooks into `extrachill_after_hero` action
- **Newsletter**: ExtraChill Newsletter Plugin provides homepage newsletter section
- **Contact Forms**: ExtraChill Contact Plugin handles all contact functionality
- **Homepage Sections**: Extensible via action hooks:
  - `extrachill_homepage_hero`
  - `extrachill_homepage_content_top`
  - `extrachill_homepage_content_middle`
  - `extrachill_homepage_content_bottom`
  - `extrachill_home_final_left`

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

## Recent Architectural Changes

- **Core Templates Directory**: Created `/inc/core/templates/` for shared template components
- **Major System Removal**: Complete removal of event submission and location filter JavaScript systems
  - Event submission functionality completely deleted (both client and server-side)
  - Location filter JavaScript removed, backend functions remain dormant in `inc/location-filter.php`
- **Plugin Migration**: Newsletter, contact forms, and session tokens moved to dedicated plugins
- **Enhanced Modularization**: Better organization of functionality by purpose with cleaner separation
- **Asset Management**: Centralized in `inc/core/assets.php` with conditional loading
- **Authentication Simplification**: Native WordPress multisite authentication replaces custom session management
- **Performance Optimization**: Removal of unused JavaScript reduces client-side complexity

## Important Notes

- **Community-Focused**: Deep integration with bbPress and native multisite authentication
- **Performance Optimized**: WooCommerce conditional loading and modular asset loading
- **Modern Architecture**: Clean separation of concerns with enhanced modular structure
- **No Build Process**: Direct file editing with WordPress native optimization
- **Extensible Design**: Action hook architecture supports plugin integration