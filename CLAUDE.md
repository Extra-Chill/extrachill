# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The ExtraChill theme is a custom WordPress theme serving as the frontend for an independent music ecosystem that includes a blog, a forum (extrachill-community theme), and a merch store (extrachill-shop theme). The theme powers extrachill.com with custom music event listings, festival coverage, community integration, and journalistic written content about the music industry.

**Current Status**: Fully converted from ColorMag Pro with performance optimizations and modern WordPress features.

## Architecture & Core Components

### Modular File Structure
The theme uses a clean, modular architecture organized in the `/inc/` directory:
- **admin/**: Administrative functionality (tag migration admin, log 404 errors, customizer)
- **archives/**: Archive page functionality (custom sorting, child terms dropdown, post cards, archive.php)
- **core/**: Essential WordPress functionality with shared templates:
  - **core/templates/**: Shared template components (post-meta, pagination, no-results, share, social-links, taxonomy-badges, breadcrumbs, searchform)
  - **core/editor/**: Custom embeds (Bandcamp, Instagram, Spotify)
  - **core/multisite/**: Cross-site integration (multisite-search, recent-activity-feed, ad-free-license, comment-author-links)
- **footer/**: Footer navigation functionality (footer-bottom-menu, footer-main-menu)
- **header/**: Navigation functionality (navigation, navigation-menu, nav-bottom-menu, nav-main-menu)
- **home/**: Homepage-specific components and template sections (templates/, homepage-queries)
- **sidebar/**: Sidebar-specific functionality (recent-posts, community-activity)
- **single/**: Single post and page functionality (comments, related-posts, single-post, single-page)

### Custom Post Types & Taxonomies
- **Custom Taxonomies**: Artist, Venue, Festival taxonomies with REST API support (defined in functions.php)
- **Festival Wire Integration**: Homepage ticker display for Festival Wire posts (handled by ExtraChill News Wire plugin)

### Multisite Integration
- **WordPress Multisite Integration**: Native WordPress multisite functions replace custom session management
- **Cross-Site Features**: Search, activity feeds, and license validation via direct database queries
### Multisite Integration (`inc/core/multisite/`)
- **Multisite Search**: Unified real-time search across sites (`inc/core/multisite/multisite-search.php`)
  - `ec_fetch_forum_results_multisite()` uses direct database queries via `switch_to_blog(2)`
  - `ec_hijack_search_query()` merges local + forum results in real-time
  - Uses hardcoded blog ID 2 for maximum performance (no database lookups)
  - No caching - always fresh results, native WordPress pagination
- **Activity Feed**: Cross-site activity integration (`inc/core/multisite/recent-activity-feed.php`)
  - `ec_fetch_recent_activity_multisite()` uses direct database queries via `switch_to_blog(2)`
- **Ad-Free License Validation**: Cross-site license validation (`inc/core/multisite/ad-free-license.php`)
  - `is_user_ad_free()` uses `switch_to_blog(3)` to check shop site's license database
  - Native WordPress multisite authentication for cross-site validation

### Plugin Integration
- **ExtraChill News Wire**: Festival Wire ticker integration via action hooks
- **ExtraChill Newsletter**: Newsletter functionality via dedicated plugin
- **ExtraChill Contact**: Contact form functionality via dedicated plugin
- **Action Hook Architecture**: Extensible plugin integration points throughout theme

### Hook-Based Menu System Architecture

The theme uses an elegant hook-based menu system that provides both performance and extensibility:

#### **Menu System Components:**

1. **Container Structure** (`inc/header/navigation-menu.php`):
   - Provides flyout menu HTML container structure
   - Includes action hooks: `extrachill_navigation_main_menu`, `extrachill_navigation_bottom_menu`
   - Handles hamburger toggle, search integration, and social links

2. **Hook Registration** (`inc/core/actions.php`):
   - Registers default handlers for navigation hooks
   - `extrachill_default_navigation_main_menu()` loads main navigation content
   - `extrachill_default_navigation_bottom_menu()` loads bottom navigation content
   - Allows plugins to override or extend menu content

3. **Content Templates**:
   - `inc/header/nav-main-menu.php` - Hardcoded main navigation items (Community, Calendar, Festival Wire, Blog Content)
   - `inc/header/nav-bottom-menu.php` - Hardcoded bottom navigation links (About, Contact)

4. **Footer Menu System**:
   - `inc/footer/footer-main-menu.php` - Hardcoded main footer menu with hierarchical structure
   - `inc/footer/footer-bottom-menu.php` - Hardcoded legal/policy links
   - Uses `extrachill_footer_main_content` and `extrachill_below_copyright` hooks

#### **Architecture Benefits:**
- **Performance**: Hardcoded menus eliminate database queries for menu generation
- **Extensibility**: Plugins can hook into menu action points to add custom items
- **Maintainability**: Menu content separated into logical, focused files
- **Admin Cleanup**: WordPress menu management interface removed (no longer needed)

#### **Plugin Integration Examples:**
```php
// Plugin can add menu items via hooks
add_action('extrachill_navigation_main_menu', 'my_plugin_add_menu_item', 15);
add_action('extrachill_footer_main_content', 'my_plugin_add_footer_section', 20);
```

#### **Menu Management:**
- No WordPress admin menu management (interface removed via `extrachill_remove_menu_admin_pages()`)
- Menu content modified directly in template files
- Hook system allows plugins to dynamically add menu items without core file modification

### CSS Architecture
- **Root Variables**: Global CSS custom properties in `assets/css/root.css`
- **Modular Loading**: Page-specific CSS files (`home.css`, `archive.css`, `single-post.css`, `nav.css`, `badge-colors.css`, `editor-style.css`)
- **Component Styles**: Separate files for badges, editor styles
- **Performance Loading**: Conditional CSS enqueuing based on page context
- **Dependency Management**: Proper CSS loading order with root.css loading first
- **Asset Directory**: All CSS files located in `assets/css/` (moved from legacy `css/` directory)

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
- **`functions.php`** - Main theme setup, asset loading, and module includes (47 PHP files)
- **`inc/core/assets.php`** - Centralized asset management with conditional loading
- **`inc/single/comments.php`** - Comment system with community integration
- **`inc/core/templates/post-meta.php`** - Post meta display template
- **`inc/archives/archive.php`** - Archive page template functionality
- **`style.css`** - Main stylesheet with CSS reset and core styles
- **`assets/css/root.css`** - CSS custom properties and theme variables

### Complete Include File Structure
**Core Shared Templates (8 files)**:
- `inc/core/templates/post-meta.php` - Post metadata display
- `inc/core/templates/pagination.php` - Native WordPress pagination
- `inc/core/templates/no-results.php` - No results found template
- `inc/core/templates/share.php` - Social sharing functionality
- `inc/core/templates/social-links.php` - Social media links
- `inc/core/templates/taxonomy-badges.php` - Taxonomy badge display
- `inc/core/templates/breadcrumbs.php` - Breadcrumb navigation
- `inc/core/templates/searchform.php` - Search form template

**Core Functionality (5 files)**:
- `inc/core/actions.php` - Centralized WordPress action hooks
- `inc/core/assets.php` - Asset management and conditional loading
- `inc/core/custom-taxonomies.php` - Custom taxonomy registration
- `inc/core/rewrite-rules.php` - URL rewrite rules
- `inc/core/yoast-stuff.php` - Yoast SEO integration

**Custom Embeds (3 files)**:
- `inc/core/editor/bandcamp-embeds.php` - Bandcamp embed support
- `inc/core/editor/instagram-embeds.php` - Instagram embed support
- `inc/core/editor/spotify-embeds.php` - Spotify embed support

**Multisite Integration (4 files)**:
- `inc/core/multisite/multisite-search.php` - Cross-site search functionality
- `inc/core/multisite/recent-activity-feed.php` - Cross-site activity feeds
- `inc/core/multisite/ad-free-license.php` - Cross-site license validation
- `inc/core/multisite/comment-author-links.php` - Cross-site comment author linking

**Sidebar Functionality (2 files)**:
- `inc/sidebar/recent-posts.php` - Recent posts sidebar widget
- `inc/sidebar/community-activity.php` - Community activity sidebar

**Archive Functionality (4 files)**:
- `inc/archives/archive-child-terms-dropdown.php` - Child terms dropdown
- `inc/archives/archive-custom-sorting.php` - Custom archive sorting
- `inc/archives/post-card.php` - Post card template
- `inc/archives/archive.php` - Main archive template

**Single Post/Page Functionality (4 files)**:
- `inc/single/single-post.php` - Single post functionality
- `inc/single/single-page.php` - Single page functionality
- `inc/single/comments.php` - Comment system
- `inc/single/related-posts.php` - Related posts functionality

**Footer Navigation (2 files)**:
- `inc/footer/footer-bottom-menu.php` - Footer bottom menu functionality
- `inc/footer/footer-main-menu.php` - Footer main menu functionality

**Header/Navigation (4 files)**:
- `inc/header/navigation.php` - Core navigation functionality
- `inc/header/navigation-menu.php` - Navigation menu functionality
- `inc/header/nav-bottom-menu.php` - Bottom navigation menu
- `inc/header/nav-main-menu.php` - Main navigation menu

**Homepage Components (8 files)**:
- `inc/home/homepage-queries.php` - Homepage query functions
- `inc/home/templates/hero.php` - Hero section
- `inc/home/templates/section-3x3-grid.php` - 3x3 grid section
- `inc/home/templates/section-more-recent-posts.php` - Recent posts section
- `inc/home/templates/section-extrachill-link.php` - ExtraChill link section
- `inc/home/templates/section-about.php` - About section
- `inc/home/templates/community-activity.php` - Community activity
- `inc/home/templates/front-page.php` - Front page template

**Admin Functionality (3 files)**:
- `inc/admin/log-404-errors.php` - 404 error logging
- `inc/admin/tag-migration-admin.php` - Tag migration administration
- `inc/admin/extrachill-customizer.php` - Theme customizer settings

### Asset Loading Strategy
- **Centralized Management**: All asset loading handled in `inc/core/assets.php`
- **Dynamic CSS Loading**: Page-specific stylesheets loaded conditionally
- **JavaScript Optimization**: Scripts enqueued only when needed (reading progress, navigation)
- **Cache Busting**: `filemtime()` versioning for all assets
- **Priority Loading**: Root CSS loads first (priority 5), other styles depend on it
- **Navigation Scripts**: Menu navigation JavaScript with conditional loading

### Template Hierarchy
- **Taxonomy Templates**: Custom templates for artist, venue, festival
- **Page Templates**: Specialized templates in `page-templates/` directory (all-posts.php)
- **Archive Templates**: Core archive functionality in `inc/archives/archive.php`
- **Festival Wire**: Custom post type functionality provided by ExtraChill News Wire plugin

## Custom Functionality

### Removed Systems
- **Newsletter System**: Functionality moved to ExtraChill Newsletter Plugin
- **Contact Form System**: Functionality moved to ExtraChill Contact Plugin
- **Session Token System**: Completely removed in favor of native WordPress multisite authentication
- **WordPress Menu Management**: Replaced with hook-based system for performance and extensibility
- **Event Submission System**: Completely removed - all JavaScript and server-side functionality deleted
- **Location System**: Complete elimination of unused location browsing functionality
- **WooCommerce Files**: All WooCommerce templates and CSS removed, moved to ExtraChill Shop plugin
- **Community Features**: Multiple community files removed (community-comments.php, community-session.php, extrachill-upvotes.php, forum-search.php, multisite-forum-search.php)
- **Legacy Template Files**: Removed content-page.php, content-single.php, content.php, comments.php, no-results.php, page.php, search.php, searchform.php, single.php
- **Legacy CSS Files**: Removed all-locations.css, woocommerce.css
- **Legacy PHP Files**: Removed breadcrumbs.php, recent-posts-in-sidebar.php, location-filter.php, contextual-search-excerpt.php

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
- **Modular Asset Loading**: CSS/JS loaded only when needed based on page context
- **Memory Management**: Memory usage tracking and optimization
- **Query Optimization**: Efficient taxonomy and post queries
- **Multisite Optimization**: Direct database queries replace REST API calls

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

- **Core Templates Directory**: Created `/inc/core/templates/` for shared template components (8 files)
- **Sidebar Directory**: Created `/inc/sidebar/` for sidebar-specific functionality (2 files)
- **Multisite Directory**: Organized `/inc/core/multisite/` for cross-site functionality (4 files)
- **Native Pagination System**: Added comprehensive pagination system replacing wp-pagenavi plugin
  - Located at `inc/core/templates/pagination.php`
  - Professional count display with context-aware navigation
  - Native WordPress pagination with proper URL parameter handling
  - Context-aware styling support for different page types
- **Asset Directory Migration**: Moved all assets from `css/` and `js/` to `assets/css/` and `assets/js/`
- **System Streamlining**: Complete removal of unused event submission, location filtering, and session token systems
- **Plugin Migration**: Newsletter, contact forms, and Festival Wire functionality moved to dedicated plugins
- **Enhanced Modularization**: Better organization with core templates directory and cleaner separation of concerns
- **Template Consolidation**: Removed legacy template files in favor of modular include system
- **Asset Management**: Centralized in `inc/core/assets.php` with conditional loading
- **Authentication Simplification**: Native WordPress multisite authentication
- **Performance Optimization**: Modular CSS architecture and selective loading
- **File Structure Cleanup**: Removed 15+ legacy PHP files and multiple CSS files no longer needed

## Important Notes

- **Multisite-Focused**: Native WordPress multisite integration with cross-site functionality
- **Performance Optimized**: Modular asset loading and selective enqueuing
- **Modern Architecture**: Clean separation of concerns with shared template components
- **No Build Process**: Direct file editing with WordPress native optimization
- **Extensible Design**: Action hook architecture supports plugin integration