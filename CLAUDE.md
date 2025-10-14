# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The ExtraChill theme is a custom WordPress theme serving as the frontend for an independent music ecosystem that includes a blog, community forums, and content management. The theme powers both extrachill.com and community.extrachill.com, with community functionality provided by the extrachill-community plugin and e-commerce handled by the extrachill-shop plugin. Features include custom music event listings, festival coverage, community integration, and journalistic written content about the music industry.

**Current Status**: Fully converted from ColorMag Pro with performance optimizations and modern WordPress features.

## Architecture & Core Components

### Modular File Structure
The theme uses a clean, modular architecture organized in the `/inc/` directory with **47 total PHP files**:
- **archives/**: Archive page functionality (9 files total)
  - Core archive files (6 files): archive.php, archive-header.php, archive-filter-bar.php, archive-custom-sorting.php, archive-child-terms-dropdown.php, post-card.php
  - **archives/search/**: Multisite search system (3 files): search.php, search-header.php, search-site-badge.php
- **core/**: Essential WordPress functionality (7 core files + 2 subdirectories)
  - Core files (7): actions.php, assets.php, custom-taxonomies.php, yoast-stuff.php, view-counts.php, rewrite.php, template-router.php
  - **core/templates/**: Shared template components (9 files): post-meta.php, pagination.php, no-results.php, share.php, social-links.php, taxonomy-badges.php, breadcrumbs.php, searchform.php, 404.php
  - **core/editor/**: Custom embeds (3 files): bandcamp-embeds.php, instagram-embeds.php, spotify-embeds.php
- **footer/**: Footer navigation functionality (2 files): footer-bottom-menu.php, footer-main-menu.php
- **header/**: Navigation functionality (4 files): navigation.php, navigation-menu.php, nav-bottom-menu.php, nav-main-menu.php
- **home/**: Homepage-specific components and template sections (8 files total: 1 core + 7 templates)
  - homepage-queries.php
  - **templates/**: 7 template files (hero.php, section-3x3-grid.php, section-more-recent-posts.php, section-extrachill-link.php, section-about.php, community-activity.php, front-page.php)
- **sidebar/**: Sidebar-specific functionality (2 files): recent-posts.php, community-activity.php
- **single/**: Single post and page functionality (4 files): comments.php, related-posts.php, single-post.php, single-page.php

### Custom Post Types & Taxonomies
- **Custom Taxonomies**: Artist, Venue, Festival taxonomies with REST API support (defined in functions.php)
- **Festival Wire Integration**: Homepage ticker display for Festival Wire posts (handled by ExtraChill News Wire plugin)

### WordPress Multisite Network Integration
The ExtraChill theme serves **all eight sites in the WordPress multisite network**:
- **extrachill.com** - Main music journalism and content site
- **community.extrachill.com** - Community forums and user hub
- **shop.extrachill.com** - E-commerce platform with WooCommerce
- **app.extrachill.com** - Mobile API backend (planning stage)
- **chat.extrachill.com** - AI chatbot system with ChatGPT-style interface
- **artist.extrachill.com** - Artist platform and profiles
- **events.extrachill.com** - Event calendar hub powered by Data Machine
- **stream.extrachill.com** - Live streaming platform (Phase 1 non-functional UI)

**Site-Specific Configuration**: Each site uses the same theme with different plugin integrations and template overrides via `extrachill_template_*` filters

**Multisite Plugin Integration**: Multisite functionality provided by network-activated plugins:
- **Cross-Site Search**: extrachill-search plugin provides `extrachill_multisite_search()` function
- **User Profile URLs**: extrachill-users plugin provides `ec_get_user_profile_url()` for intelligent routing
- **Community Activity**: Theme directly queries bbPress data via `switch_to_blog()` with manual URL construction
- **Direct Database Access**: Theme uses WordPress native `WP_Query` for bbPress topics/replies
- **Manual URL Construction**: Forum URLs constructed as `https://community.extrachill.com/r/{forum-slug}`
- **WordPress Object Cache**: 10-minute caching via `wp_cache_get()` and `wp_cache_set()`
- **Network-Wide Security**: Admin access control provided by extrachill-multisite plugin

### Plugin Integration
- **ExtraChill Multisite**: Network-activated centralized functionality across all sites
- **ExtraChill Community**: Community and forum functionality integration for community.extrachill.com
- **ExtraChill News Wire**: Festival Wire ticker integration via action hooks
- **ExtraChill Newsletter**: Newsletter functionality via dedicated plugin
- **ExtraChill Contact**: Contact form functionality via dedicated plugin
- **ExtraChill Shop**: E-commerce functionality via dedicated plugin
- **ExtraChill Events**: Event management functionality (replaces dm-events integration)
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

### Universal Template Routing System

The theme implements template routing via WordPress's native `template_include` filter through `/inc/core/template-router.php`:

#### **Router Architecture:**

1. **WordPress Native Integration** (`inc/core/template-router.php`):
   - Uses WordPress's `template_include` filter for proper integration with template hierarchy
   - Routes all page types: homepage, single posts, pages, archives, search, 404
   - Plugin override support via dedicated `extrachill_template_*` filters for each route
   - **Custom Page Template Support**: Automatically detects and respects custom page templates assigned via WordPress admin
   - When a page has a custom template assigned (via `get_page_template_slug()`), the router bypasses filter hooks and lets WordPress handle template selection naturally
   - `index.php` serves as minimal emergency fallback only

2. **Filter-Based Override System**:
   - Each template route supports dedicated filter: `extrachill_template_*`
   - Plugins can completely override template files at the routing level
   - Maintains backward compatibility while enabling deep customization

3. **Supported Routes**:
   - `extrachill_template_homepage` - Front page and home page routing
   - `extrachill_template_single_post` - Single post template override
   - `extrachill_template_page` - Page template override (only applied when no custom page template is assigned)
   - `extrachill_template_archive` - Archive, category, tag, author, date pages
   - `extrachill_template_search` - Search results (uses dedicated multisite search template)
   - `extrachill_template_404` - 404 error pages
   - `extrachill_template_fallback` - Unknown page types fallback

4. **Custom Page Template Handling**:
   - Router checks for custom page templates via `get_page_template_slug()` before applying filters
   - If a custom template is assigned and exists in the theme, the router returns WordPress's natural template selection
   - This allows standard WordPress page templates (e.g., `page-templates/all-posts.php`) to work without plugin interference
   - Filter `extrachill_template_page` only applies when no custom template is assigned
   - Maintains full compatibility with WordPress's native template system

#### **Benefits:**
- **Plugin Control**: Plugins can override entire template structures via filters
- **WordPress Native**: Proper integration with WordPress core via `template_include` filter
- **Centralized Logic**: All routing decisions in dedicated router file
- **Performance**: Efficient routing while maintaining WordPress compatibility
- **Extensibility**: Filter system allows complete template customization

### CSS Architecture
- **Root Variables**: Global CSS custom properties in `assets/css/root.css`
- **Modular Loading**: Page-specific CSS files (9 total: `home.css`, `archive.css`, `single-post.css`, `nav.css`, `badge-colors.css`, `editor-style.css`, `search.css`, `shared-tabs.css`, `root.css`)
- **Component Styles**: Separate files for badges, editor styles, search results, and shared tab interfaces
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
```

**Universal Build Script**: Symlinked to shared build script at `../.github/build.sh`

**Build process:**
- Auto-detects WordPress theme from `Theme Name:` header in `style.css`
- Extracts version from theme header for validation and logging
- Installs production dependencies: `composer install --no-dev`
- Copies files using rsync with `.buildignore` exclusion patterns
- Validates theme structure (ensures `style.css` and `index.php` exist)
- Creates `/build/extrachill/` clean production directory
- Creates `/build/extrachill.zip` non-versioned deployment package
- Restores development dependencies: `composer install`

**Output**: Both `/build/extrachill/` directory AND `/build/extrachill.zip` file exist simultaneously

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
- **`inc/core/template-router.php`** - WordPress native template routing via `template_include` filter with custom page template support
- **`index.php`** - Emergency fallback template (minimal functionality)
- **`functions.php`** - Main theme setup, asset loading, and module includes (23 direct includes from 47 total PHP files in /inc/)
- **`inc/core/assets.php`** - Centralized asset management with conditional loading
- **`inc/single/comments.php`** - Comment system with community integration
- **`inc/core/templates/post-meta.php`** - Post meta display template
- **`inc/archives/archive.php`** - Archive page template functionality
- **`inc/archives/search/search.php`** - Multisite search template with cross-site results
- **`style.css`** - Main stylesheet with CSS reset and core styles
- **`assets/css/root.css`** - CSS custom properties and theme variables

### Complete Include File Structure
**Core Shared Templates (9 files)**:
- `inc/core/templates/post-meta.php` - Post metadata display
- `inc/core/templates/pagination.php` - Native WordPress pagination
- `inc/core/templates/no-results.php` - No results found template
- `inc/core/templates/share.php` - Social sharing functionality
- `inc/core/templates/social-links.php` - Social media links
- `inc/core/templates/taxonomy-badges.php` - Taxonomy badge display
- `inc/core/templates/breadcrumbs.php` - Breadcrumb navigation
- `inc/core/templates/searchform.php` - Search form template
- `inc/core/templates/404.php` - 404 error page template

**Core Functionality (7 files)**:
- `inc/core/actions.php` - Centralized WordPress action hooks
- `inc/core/assets.php` - Asset management and conditional loading
- `inc/core/custom-taxonomies.php` - Custom taxonomy registration
- `inc/core/yoast-stuff.php` - Yoast SEO integration
- `inc/core/view-counts.php` - Universal view counting for all post types
- `inc/core/rewrite.php` - Category base rewriting for multisite permalink consistency
- `inc/core/template-router.php` - WordPress native template routing via `template_include` filter

**Custom Embeds (3 files)**:
- `inc/core/editor/bandcamp-embeds.php` - Bandcamp embed support
- `inc/core/editor/instagram-embeds.php` - Instagram embed support
- `inc/core/editor/spotify-embeds.php` - Spotify embed support

**Universal Template Routing**:
- `inc/core/template-router.php` handles routing via WordPress's `template_include` filter
- `index.php` serves as emergency fallback only (minimal functionality)
- **Custom Page Template Support**: Router detects and respects custom page templates assigned via WordPress admin
- Filter hooks allow plugins to completely override template files at routing level (except when custom page templates are assigned)
- Template routing supports: homepage, single posts, pages, archives, search, and 404 pages
- Search routes to dedicated multisite search template at `inc/archives/search/search.php`
- 404 errors route to `inc/core/templates/404.php`
- Each route includes `extrachill_template_*` filter for plugin customization

**Multisite Search System (3 files)**:
- `inc/archives/search/search.php` - Main multisite search template using `extrachill_multisite_search()`
- `inc/archives/search/search-header.php` - Search results header with result counts
- `inc/archives/search/search-site-badge.php` - Site identification badges for cross-site results

**Multisite Integration**:
- Cross-site search provided by extrachill-search plugin (network-activated)
- User profile URL resolution provided by extrachill-users plugin (network-activated)
- Community activity widgets directly query bbPress data via `switch_to_blog()`
- Theme manually constructs forum URLs: `https://community.extrachill.com/r/{forum-slug}`
- WordPress object cache used for 10-minute activity caching
- Function existence checks for `ec_get_user_profile_url()` from extrachill-users plugin

**Sidebar Functionality (2 files)**:
- `inc/sidebar/recent-posts.php` - Recent posts sidebar widget
- `inc/sidebar/community-activity.php` - Direct bbPress query widget with blog switching and manual URL construction

**Archive Functionality (9 files total)**:
- `inc/archives/archive.php` - Main archive template
- `inc/archives/archive-header.php` - Archive page header component
- `inc/archives/archive-filter-bar.php` - Archive filtering interface with randomization and sorting
- `inc/archives/archive-child-terms-dropdown.php` - Child terms dropdown
- `inc/archives/archive-custom-sorting.php` - Custom archive sorting functions
- `inc/archives/post-card.php` - Post card template
- **Search subdirectory (3 files)**: search.php, search-header.php, search-site-badge.php

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
- `inc/home/templates/community-activity.php` - Direct bbPress query with blog switching for 3x3 grid display
- `inc/home/templates/front-page.php` - Front page template


### Asset Loading Strategy
- **Centralized Management**: All asset loading handled in `inc/core/assets.php`
- **Dynamic CSS Loading**: Page-specific stylesheets loaded conditionally
- **JavaScript Optimization**: Scripts enqueued only when needed (reading progress, navigation)
- **Cache Busting**: `filemtime()` versioning for all assets
- **Priority Loading**: Root CSS loads first (priority 5), other styles depend on it
- **Navigation Scripts**: Menu navigation JavaScript with conditional loading

### Template Hierarchy
- **WordPress Native Router**: `inc/core/template-router.php` handles routing via `template_include` filter
- **Emergency Fallback**: `index.php` provides minimal fallback functionality
- **Template Filters**: Each page type supports `extrachill_template_*` filters for plugin customization
- **Modular Templates**: Core functionality organized in `/inc/` directory structure
- **Archive Templates**: Core archive functionality in `inc/archives/archive.php`
- **Multisite Search**: Dedicated search template system in `inc/archives/search/` with cross-site results
- **404 Pages**: 404 template at `inc/core/templates/404.php`
- **Festival Wire**: Custom post type functionality provided by ExtraChill News Wire plugin

## Custom Functionality

### Removed Systems
- **Template Override System**: Replaced with WordPress native routing via template-router.php
- **Newsletter System**: Functionality moved to ExtraChill Newsletter Plugin
- **Contact Form System**: Functionality moved to ExtraChill Contact Plugin
- **Session Token System**: Completely removed in favor of native WordPress multisite authentication
- **WordPress Menu Management**: Replaced with hook-based system for performance and extensibility
- **Event Submission System**: Completely removed - all JavaScript and server-side functionality deleted
- **Location System**: Complete elimination of unused location browsing functionality
- **WooCommerce Files**: All WooCommerce templates and CSS removed, moved to ExtraChill Shop plugin
- **Community Features**: Community functionality streamlined with external plugin integration
- **Legacy Template Files**: Removed content-page.php, content-single.php, content.php, comments.php, no-results.php, page.php, search.php, searchform.php, single.php
- **Legacy CSS Files**: Removed all-locations.css, woocommerce.css
- **Legacy PHP Files**: Removed breadcrumbs.php, recent-posts-in-sidebar.php, location-filter.php, contextual-search-excerpt.php
- **dm-events Integration**: Removed in favor of extrachill-events plugin integration

### Plugin Integration Points
- **Multisite Features**: ExtraChill Multisite Plugin provides network-activated centralized functionality
- **Community Features**: ExtraChill Community Plugin provides forum functionality for community.extrachill.com
- **Festival Wire**: ExtraChill News Wire plugin hooks into `extrachill_after_hero` action
- **Newsletter**: ExtraChill Newsletter Plugin provides homepage newsletter section
- **Contact Forms**: ExtraChill Contact Plugin handles all contact functionality
- **E-commerce**: ExtraChill Shop Plugin provides shop functionality for shop.extrachill.com
- **Event Management**: ExtraChill Events Plugin provides calendar and event functionality
- **Template Override Points**: Universal router supports template override via filters:
  - `extrachill_template_homepage`
  - `extrachill_template_single_post`
  - `extrachill_template_page` (only when no custom page template assigned)
  - `extrachill_template_archive`
  - `extrachill_template_search`
  - `extrachill_template_404`
  - **Note**: Custom page templates assigned via WordPress admin bypass filter hooks
- **Homepage Sections**: Extensible via action hooks:
  - `extrachill_homepage_hero`
  - `extrachill_homepage_content_top`
  - `extrachill_homepage_content_middle`
  - `extrachill_homepage_content_bottom`
  - `extrachill_home_final_left`

### Performance Features
- **Modular Asset Loading**: CSS/JS loaded only when needed based on page context
- **View Count Tracking**: Universal post view counting system with editor/admin exclusion
- **Permalink Optimization**: Category base rewriting for consistent multisite URLs
- **Memory Management**: Memory usage tracking and optimization
- **Query Optimization**: Efficient taxonomy and post queries
- **Multisite Optimization**: Direct database queries provided by extrachill-multisite plugin

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

- **Modular Organization**: Clean, organized procedural code structure
- **WordPress Hooks**: Extensive use of filters for extensibility
- **Translation Ready**: All strings properly internationalized
- **Accessibility**: Semantic HTML and ARIA attributes
- **REST API**: Custom taxonomies support REST API integration

## Recent Architectural Changes

- **WordPress Native Template Routing**: Implemented `inc/core/template-router.php` using `template_include` filter
- **Template Router Migration**: Moved routing logic from `index.php` to dedicated router file for proper WordPress integration
- **Emergency Fallback**: `index.php` now serves as minimal emergency fallback only
- **Multisite Plugin Migration**: All multisite functionality moved to extrachill-multisite plugin for network activation
- **Core Templates Directory**: Created `/inc/core/templates/` for shared template components (8 files)
- **Sidebar Directory**: Created `/inc/sidebar/` for sidebar-specific functionality (2 files)
- **Native Pagination System**: Added comprehensive pagination system replacing wp-pagenavi plugin
  - Located at `inc/core/templates/pagination.php`
  - Professional count display with context-aware navigation
  - Native WordPress pagination with proper URL parameter handling
  - Context-aware styling support for different page types
- **Asset Directory Migration**: Moved all assets from `css/` and `js/` to `assets/css/` and `assets/js/`
- **System Streamlining**: Complete removal of unused event submission, location filtering, and session token systems
- **Plugin Migration**: Newsletter, contact forms, multisite functionality, and Festival Wire moved to dedicated plugins
- **Enhanced Modularization**: Better organization with core templates directory and cleaner separation of concerns
- **Template Consolidation**: Removed legacy template files in favor of modular include system with universal routing
- **Asset Management**: Centralized in `inc/core/assets.php` with conditional loading
- **Authentication Simplification**: Native WordPress multisite authentication via extrachill-multisite plugin
- **Performance Optimization**: Modular CSS architecture and selective loading
- **File Structure Cleanup**: Streamlined to 47 modular PHP files with improved organization (23 directly loaded in functions.php)
- **Custom Page Template Support**: Router automatically respects custom page templates assigned via WordPress admin
- **Button Style Standardization**: Unified button classes across the entire ecosystem
- **View Counting System**: Universal post view tracking with `ec_get_post_views()`, `ec_the_post_views()`, and `ec_track_post_views()`
- **Rewrite System**: Category base rewriting for consistent multisite permalink structures
- **Sticky Header Filter**: `extrachill_enable_sticky_header` filter allows plugins to control sticky header behavior
- **Event Integration Update**: Replaced dm-events integration with extrachill-events plugin
- **Multisite Search System**: Dedicated search template system with cross-site search integration
- **View Counting System**: Post view count tracking functionality
- **Custom Rewrite Rules**: Enhanced URL rewriting system
- **Shared Components**: Reusable tab interface system with CSS and JavaScript

## Important Notes

- **Dual-Site Theme**: Serves both extrachill.com and community.extrachill.com with plugin-based functionality
- **Multisite-Focused**: Native WordPress multisite integration with cross-site functionality
- **Plugin Architecture**: Community and multisite features provided by dedicated plugins rather than theme integration
- **Performance Optimized**: Modular asset loading and selective enqueuing
- **Modern Architecture**: Clean separation of concerns with shared template components
- **No Build Process**: Direct file editing with WordPress native optimization
- **Extensible Design**: Action hook architecture supports plugin integration