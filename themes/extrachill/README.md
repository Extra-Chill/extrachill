# ExtraChill WordPress Theme

A custom WordPress theme (v1.0.5) powering the ExtraChill Platform multisite network with 10 interconnected sites, featuring blog content, community integration, festival coverage, and e-commerce functionality.

## Overview

ExtraChill is a modern, performance-optimized WordPress theme (v1.0.5) designed specifically for the Extra Chill Platform multisite network. It serves as the frontend for all 10 interconnected sites:

- **extrachill.com** (Blog ID 1): Music journalism, artist features, and industry coverage
- **community.extrachill.com** (Blog ID 2): Community forums with bbPress integration
- **shop.extrachill.com** (Blog ID 3): E-commerce with WooCommerce integration
- **artist.extrachill.com** (Blog ID 4): Artist platform and profiles
- **chat.extrachill.com** (Blog ID 5): AI chatbot interface
- **events.extrachill.com** (Blog ID 7): Event calendar hub
- **stream.extrachill.com** (Blog ID 8): Live streaming platform
- **newsletter.extrachill.com** (Blog ID 9): Newsletter management and archive
- **horoscope.extrachill.com** (Blog ID 10): Daily horoscopes
- **extrachill.link**: Artist link pages (domain-mapped to artist.extrachill.com)

## Key Features

### 🎵 Music-Focused Content Management
- **Custom Taxonomies**: Artist, Venue, and Festival organization with REST API support
- **Festival Wire Integration**: Homepage ticker display for festival coverage (requires ExtraChill News Wire plugin)
- **Newsletter Integration**: Newsletter functionality provided by ExtraChill Newsletter Plugin
- **Contact Forms**: Contact form functionality provided by ExtraChill Contact Plugin
- **Search Integration**: Search header template and CSS provided by theme, main template provided by extrachill-search plugin

### 🚀 Performance Optimizations
- **Conditional Asset Loading**: CSS/JS loaded only when needed based on page context
- **Modular CSS Architecture**: Page-specific stylesheets (home.css, archive.css, single-post.css, nav.css)
- **Image Optimization**: Unnecessary WordPress image sizes removed
- **Memory Management**: Efficient resource usage with memory tracking
- **Multisite Optimization**: Direct database queries replace REST API calls for better performance

### 🎨 Modern Design System
- **Modular CSS Architecture**: Component-based styling with CSS custom properties in `assets/css/`
- **CSS Variables**: Global design tokens in `assets/css/root.css`
- **Responsive Design**: Mobile-first approach with flexible layouts
- **Component Styles**: Dedicated files for badges (`badge-colors.css`), editor styles (`editor-style.css`), search results (`search.css`), shared tabs (`shared-tabs.css`)

### 🤝 Community Integration
- **WordPress Multisite**: Native cross-domain authentication and user management across 10 sites
- **Shared Community Activity Helper**: Centralized reusable library (`inc/core/templates/community-activity.php`) provides `extrachill_get_community_activity_items()` and `extrachill_render_community_activity()` functions
- **Multi-Site Activity Queries**: Queries BOTH community.extrachill.com (blog ID 2) AND artist.extrachill.com (blog ID 4) for bbPress topics/replies
- **Activity Integration**: Merges activities from both sites into unified, chronologically sorted list with 10-minute caching
- **Activity Feeds**: Two widgets (sidebar and homepage) calling shared helper with different styling configurations
- **Multisite Search**: Cross-site search via extrachill-search plugin with `extrachill_multisite_search()`
- **Profile URL Resolution**: Intelligent routing via extrachill-users plugin `ec_get_user_profile_url()`
- **Direct Database Access**: Theme uses WordPress native WP_Query via `switch_to_blog()` for optimal performance
- **Fallback Handling**: Graceful degradation when network plugins unavailable

## Installation

### Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher

### Setup Instructions

1. **Download the theme**:
   ```bash
   git clone [repository-url] extrachill
   ```

2. **Install in WordPress**:
   - Upload the theme folder to `/wp-content/themes/`
   - Activate through WordPress admin

3. **Configure recommended plugins**:
   - ExtraChill Multisite (network-activated - core multisite functionality)
   - ExtraChill Search (network-activated - cross-site search)
   - ExtraChill Users (network-activated - user profile URL resolution and avatar menu)
   - ExtraChill News Wire (Festival Wire functionality)
   - ExtraChill Newsletter (newsletter functionality)
   - ExtraChill Contact (contact form functionality)
   - ExtraChill Events (event management functionality)

4. **Initial setup**:
   ```bash
   wp rewrite flush
   wp theme mod set custom_logo [logo-id]
   ```

## Development Workflow

### Local Development

The theme supports direct file editing for development with no build step required:

```bash
# Navigate to theme directory
cd wp-content/themes/extrachill

# Edit files directly
# CSS files are in /assets/css/ (11 CSS files: root.css, home.css, archive.css, single-post.css, nav.css, badge-colors.css, editor-style.css, search.css, shared-tabs.css, share.css, sidebar.css)
# JavaScript files are in /assets/js/ (4 files: nav-menu.js, reading-progress.js, chill-custom.js, shared-tabs.js)
# PHP files use modular include structure in /inc/ directory (48 total files, 28 directly loaded in functions.php)

# Check for syntax errors
php -l functions.php

# Enable WordPress debugging
# Add to wp-config.php:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('SCRIPT_DEBUG', true);
```

### Production Deployment

For production deployment, use the build script to create clean ZIP packages:

```bash
# Create production-ready ZIP file
./build.sh

# Output will be in build/ directory:
# - build/extrachill.zip (deployable ZIP file only - unzip when directory access needed)
```

#### Build Process Features
- **File Exclusion**: Uses `.buildignore` to exclude development files
- **Production Dependencies**: Installs production-only Composer dependencies
- **Structure Validation**: Verifies all essential theme files are present
- **Clean Packaging**: Creates optimized ZIP file for WordPress deployment
- **Development Restoration**: Automatically restores dev dependencies after build

### File Structure

```
extrachill/
├── index.php                   # Emergency fallback template (minimal functionality)
├── assets/                     # Theme assets directory
│   ├── css/                    # Modular CSS files (11 files)
│   │   ├── root.css            # CSS custom properties
│   │   ├── home.css            # Homepage styles
│   │   ├── archive.css         # Archive page styles
│   │   ├── single-post.css     # Single post styles
│   │   ├── nav.css             # Navigation styles
│   │   ├── badge-colors.css    # Taxonomy badge colors
│   │   ├── editor-style.css    # Block editor styles
│   │   ├── search.css          # Search results styles
│   │   └── shared-tabs.css     # Shared tab interface styles
│   ├── js/                     # JavaScript files (4 files)
│   │   ├── nav-menu.js         # Navigation menu functionality
│   │   ├── reading-progress.js # Reading progress indicator
│   │   ├── chill-custom.js     # Custom functionality
│   │   └── shared-tabs.js      # Shared tab interface
│   └── fonts/                  # Local web fonts
├── inc/                        # Modular PHP functionality (48 files total, 28 directly loaded)
│   ├── archives/               # Archive page functionality (8 files)
│   │   ├── archive.php
│   │   ├── archive-header.php
│   │   ├── archive-filter-bar.php
│   │   ├── archive-child-terms-dropdown.php
│   │   ├── archive-custom-sorting.php
│   │   ├── artist-profile-link.php  # Artist profile integration linking to artist.extrachill.com
│   │   ├── post-card.php
│   │   └── search/             # Search integration (1 file)
│   │       └── search-header.php  # Search header (via extrachill_search_header hook)
│   ├── core/                   # Core WordPress features (7 files + 2 subdirectories)
│   │   ├── templates/          # Shared template components (10 files)
│   │   │   ├── post-meta.php
│   │   │   ├── pagination.php
│   │   │   ├── no-results.php
│   │   │   ├── share.php
│   │   │   ├── social-links.php
│   │   │   ├── taxonomy-badges.php
│   │   │   ├── breadcrumbs.php
│   │   │   ├── searchform.php
│   │   │   ├── 404.php
│   │   │   └── community-activity.php  # Shared community activity helper library
│   │   ├── editor/             # Custom embeds (3 files)
│   │   │   ├── bandcamp-embeds.php
│   │   │   ├── instagram-embeds.php
│   │   │   └── spotify-embeds.php
│   │   ├── actions.php         # Centralized WordPress action hooks
│   │   ├── assets.php          # Asset management
│   │   ├── custom-taxonomies.php
│   │   ├── yoast-stuff.php
│   │   ├── view-counts.php     # Universal post view tracking
│   │   ├── rewrite.php         # Category base rewriting
│   │   └── template-router.php # WordPress native template routing
│   ├── footer/                 # Footer navigation functionality (3 files)
│   │   ├── footer-bottom-menu.php
│   │   ├── footer-main-menu.php
│   │   └── back-to-home-link.php  # Universal back-to-home navigation with smart logic
│   ├── header/                 # Navigation functionality (3 files)
│   │   ├── navigation-menu.php
│   │   ├── nav-bottom-menu.php
│   │   └── nav-main-menu.php
│   ├── home/                   # Homepage components (8 files: 1 + 7 templates)
│   │   ├── homepage-queries.php
│   │   └── templates/          # Homepage template sections (7 files)
│   │       ├── hero.php
│   │       ├── section-3x3-grid.php
│   │       ├── section-more-recent-posts.php
│   │       ├── section-extrachill-link.php
│   │       ├── section-about.php
│   │       ├── community-activity.php  # Legacy wrapper (deprecated)
│   │       └── front-page.php
│   ├── sidebar/                # Sidebar functionality (2 files)
│   │   ├── recent-posts.php
│   │   └── community-activity.php
│   └── single/                 # Single post/page functionality (4 files)
│       ├── comments.php
│       ├── related-posts.php
│       ├── single-post.php
│       └── single-page.php
└── page-templates/             # Custom page templates
    └── all-posts.php
```

### Adding New Features

1. **Create modular files** in appropriate `/inc/` subdirectory
2. **Include in functions.php** with proper dependencies
3. **Add styles** in dedicated CSS file with conditional loading
4. **Test thoroughly** with WordPress debugging enabled
5. **For production**: Run `./build.sh` to create deployable package

### CSS Development

- **Root variables** go in `assets/css/root.css`
- **Page-specific styles** in separate CSS files
- **Component styles** in dedicated files
- **Use WordPress enqueuing** with `filemtime()` versioning

### JavaScript Development

- **Conditional loading** based on page context
- **WordPress localization** for AJAX URLs and nonces
- **Error handling** and fallbacks
- **Cache busting** with dynamic versioning

## Architecture

### Universal Template Routing System

The theme implements template routing via WordPress's native `template_include` filter through `/inc/core/template-router.php`:

- **WordPress Native Integration**: Uses `template_include` filter for proper integration with WordPress core
- **Router File**: All routing logic centralized in `inc/core/template-router.php`
- **Emergency Fallback**: `index.php` serves as minimal emergency fallback only
- **Custom Page Template Support**: Router automatically detects and respects custom page templates assigned via WordPress admin (e.g., `page-templates/all-posts.php`)
- **Plugin Override Support**: Filter hooks allow plugins to completely override template files at routing level (except when custom page templates are assigned)
- **Supported Routes**: `extrachill_template_homepage`, `extrachill_template_single_post`, `extrachill_template_page` (only when no custom template assigned), `extrachill_template_archive`, `extrachill_template_search`, `extrachill_template_404`, `extrachill_template_fallback`
- **Performance Benefits**: Efficient routing while maintaining WordPress compatibility
- **Extensibility**: Filter system allows complete template customization

### Modular Design

The theme follows a modular architecture with clear separation of concerns:

- **Core WordPress functionality** in `/inc/core/` (7 core files + 2 subdirectories = 20 total files)
- **Shared template components** in `/inc/core/templates/` (10 reusable templates including 404.php and community-activity.php)
- **Custom embeds** in `/inc/core/editor/` (3 embed types: Bandcamp, Spotify, Instagram)
- **Archive functionality** in `/inc/archives/` (7 core files + search subdirectory with 1 file = 8 total files)
- **Search integration** in `/inc/archives/search/` (1 file: search-header.php used via extrachill_search_header hook by extrachill-search plugin)
- **Footer navigation** in `/inc/footer/` (3 footer files: main menu, bottom menu, back-to-home navigation)
- **Header navigation system** in `/inc/header/` (3 navigation files)
- **Homepage components** in `/inc/home/` (8 homepage files: 1 core + 7 templates)
- **Sidebar functionality** in `/inc/sidebar/` (2 sidebar files)
- **Single post/page features** in `/inc/single/` (4 single files)
- **Total**: 48 PHP files in `/inc/` directory with 28 directly loaded in functions.php

### Hook-Based Menu System

The theme features a sophisticated hook-based menu system that replaces WordPress's native menu management:

- **Performance**: Hardcoded menus eliminate database queries for menu generation
- **Extensibility**: Plugins can hook into `extrachill_navigation_main_menu` and `extrachill_footer_main_content` to add menu items
- **Maintainability**: Menu content separated into focused template files (`nav-main-menu.php`, `footer-main-menu.php`)
- **Admin Cleanup**: WordPress menu management interface removed via `extrachill_remove_menu_admin_pages()`

The system uses action hooks registered in `inc/core/actions.php` to load hardcoded menu templates, allowing both performance optimization and plugin extensibility without the overhead of WordPress's menu system.

### Performance Features

- **WordPress native routing**: Template routing via `template_include` filter in `inc/core/template-router.php`
- **Modular asset loading**: CSS/JS loaded only when needed based on page context via `inc/core/assets.php`
- **Native pagination system**: Lightweight WordPress pagination in `inc/core/templates/pagination.php`
- **Memory optimization**: Efficient resource usage tracking and admin style dequeuing
- **Query optimization**: Direct database queries for multisite integration
- **Asset optimization**: SVG support, emoji script removal, unnecessary image size removal
- **Conditional loading**: WooCommerce styles, admin styles, and plugin styles loaded only when needed
- **View count tracking**: Universal post view counting system with editor/admin exclusion in `inc/core/view-counts.php`
- **Permalink optimization**: Category base rewriting for consistent multisite URLs in `inc/core/rewrite.php`

### Security Implementation

- **Output escaping**: All output properly escaped
- **Input sanitization**: All inputs sanitized
- **Nonce verification**: AJAX requests protected
- **Capability checks**: Admin functions secured

## Customization

### Theme Options

Access theme customization through:
- WordPress Customizer: `Appearance > Customize`
- Custom admin settings: `ExtraChill > Settings`

### Custom CSS

Add custom styles through:
1. **Child theme** (recommended for major changes)
2. **WordPress Customizer** Additional CSS
3. **Custom CSS files** in `/assets/css/` directory

### Hooks and Filters

The theme provides extensive hooks for customization:

```php
// Customize homepage content sections
add_action('extrachill_homepage_hero', 'custom_hero_section');
add_action('extrachill_homepage_content_top', 'custom_content_section');

// Override page template (only when no custom page template assigned)
add_filter('extrachill_template_page', function($template) {
    return MY_PLUGIN_DIR . '/custom-page.php';
});

// Disable sticky header
add_filter('extrachill_enable_sticky_header', '__return_false');

// Add custom functionality to final homepage section
add_action('extrachill_home_final_left', 'custom_final_section');
```

## Troubleshooting

### Common Issues

**Festival Wire not displaying**:
- Ensure ExtraChill News Wire plugin is installed and activated
- Check that Festival Wire posts exist in WordPress admin
- Verify plugin hook integration with theme

**Community activity not displaying**:
- Ensure both community.extrachill.com (blog ID 2) and artist.extrachill.com (blog ID 4) sites exist in network
- Check that bbPress plugin is installed on both community and artist sites
- Verify shared helper library exists at `inc/core/templates/community-activity.php`
- Verify extrachill-users plugin is network-activated (for `ec_get_user_profile_url()`)
- Check WordPress object cache is working (10-minute cache)
- Review error logs for blog switching issues

### Debug Mode

Enable debugging for development:

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('SCRIPT_DEBUG', true);
```

## Recent Changes (v1.0.0+)

### New Modular Architecture
- **WordPress Native Template Routing**: Implemented `inc/core/template-router.php` using `template_include` filter
- **Template Router Migration**: Moved routing logic from `index.php` to dedicated router file for proper WordPress integration
- **Emergency Fallback**: `index.php` now serves as minimal emergency fallback only
- **Core Templates Directory**: Created `/inc/core/templates/` for 10 shared template components
- **Sidebar Directory**: Created `/inc/sidebar/` for sidebar-specific functionality
- **Community Activity Refactor**: Centralized shared helper library in `inc/core/templates/community-activity.php` with reusable `extrachill_get_community_activity_items()` and `extrachill_render_community_activity()` functions
- **Multi-Site Activity**: Queries both community.extrachill.com (blog ID 2) and artist.extrachill.com (blog ID 4) for unified activity display
- **Multisite Plugin Separation**: Functionality distributed across extrachill-multisite, extrachill-search, and extrachill-users
- **Native Pagination System**: Replaced wp-pagenavi plugin with lightweight, native WordPress pagination
  - Located at `inc/core/templates/pagination.php`
  - Professional post count display with context-aware navigation
  - Proper URL parameter handling for filtered views
  - Context-specific styling support

### System Removals (15+ Files Deleted)
- **Template Override System**: Replaced with WordPress native routing via template-router.php
- **Event Submission System**: Complete removal of event submission functionality
- **Location System**: Complete elimination of unused location browsing functionality
- **Session Token System**: Completely removed in favor of native WordPress multisite authentication
- **WordPress Menu Management**: Replaced with hook-based system for performance and extensibility
- **WooCommerce Files**: All WooCommerce templates and CSS removed, moved to ExtraChill Shop plugin
- **Community Files**: Removed community-comments.php, community-session.php, extrachill-upvotes.php, forum-search.php, multisite-forum-search.php
- **Legacy Templates**: Removed content-page.php, content-single.php, content.php, comments.php, no-results.php, page.php, search.php, searchform.php, single.php
- **Legacy CSS**: Removed all-locations.css, woocommerce.css
- **Legacy PHP**: Removed breadcrumbs.php, recent-posts-in-sidebar.php, location-filter.php, contextual-search-excerpt.php
- **Multisite Functions**: Moved all multisite functionality to extrachill-multisite plugin

### Performance Improvements
- **Asset Directory Migration**: Moved all assets from `css/` and `js/` to `assets/css/` and `assets/js/`
- **CSS Modularization**: 11 dedicated CSS files with conditional loading:
  - `assets/css/root.css` - CSS custom properties
  - `assets/css/home.css` - Homepage styles
  - `assets/css/archive.css` - Archive page styles
  - `assets/css/single-post.css` - Single post styles
  - `assets/css/nav.css` - Navigation styles
  - `assets/css/badge-colors.css` - Taxonomy badge colors
  - `assets/css/editor-style.css` - Block editor styles
  - `assets/css/search.css` - Search results styles
  - `assets/css/shared-tabs.css` - Shared tab interface
  - `assets/css/share.css` - Social share buttons
  - `assets/css/sidebar.css` - Sidebar components
- **Streamlined asset loading**: Conditional CSS/JS enqueuing based on page context via `inc/core/assets.php`
- **Memory optimization**: Efficient resource management through selective loading and admin style dequeuing
- **Multisite optimization**: Plugin architecture with function existence checks and caching
- **Template consolidation**: 48 modular PHP files replace monolithic template structure (28 directly loaded in functions.php)
- **Font file cleanup**: Removed legacy font files (Libre Franklin, PT Serif, Wilco Loft Sans, Lobster, owfont) for performance optimization
- **WooCommerce template router support**: Added `is_woocommerce()` bypass in template router for native WooCommerce template handling
- **Search plugin integration**: Theme provides search header template and CSS, extrachill-search plugin provides main template
- **View counting**: Universal post view tracking with `ec_get_post_views()`, `ec_the_post_views()`, and `ec_track_post_views()`
- **Permalink consistency**: Category base rewriting for consistent multisite URL structures

## Support and Contributing

### Getting Help

- Check the [CLAUDE.md](CLAUDE.md) file for detailed technical information
- Review error logs in `/wp-content/debug.log`
- Inspect browser console for JavaScript errors

### Contributing

1. Follow WordPress coding standards
2. Use PSR-4 namespacing where applicable
3. Include comprehensive inline documentation
4. Test all changes thoroughly
5. Maintain modular architecture

## License

This theme is proprietary software developed for ExtraChill.com. All rights reserved.

## Changelog

### Version 1.0.0+
- **WordPress Native Template Routing**: Implemented `inc/core/template-router.php` using `template_include` filter
- **Template Router Migration**: Moved routing logic from `index.php` to dedicated router file
- **Emergency Fallback**: `index.php` now serves as minimal emergency fallback only
- **Modular Architecture**: 48 PHP files organized in 7 directories with clear separation of concerns (28 directly loaded in functions.php)
- **Community Activity Refactor**: Centralized shared helper library with reusable functions for multi-site activity display
- **Archive Sorting Enhancement**: Upgraded to 4-option dropdown (recent, oldest, random, popular by view count)
- **Artist Profile Integration**: Displays "View Artist Profile" button on artist archives when matching profile exists on artist.extrachill.com
- **Universal Back-to-Home Navigation**: Smart context-aware navigation (hidden on main homepage, links to main site on subsite homepages, links to current site homepage on all other pages)
- **Custom Page Template Support**: Router respects custom page templates assigned via WordPress admin
- **Button Style Standardization**: Unified button classes across the entire ecosystem
- **Asset Migration**: Complete move from legacy `css/` and `js/` to `assets/css/` and `assets/js/`
- **Template System**: 10 shared template components in `/inc/core/templates/` (including 404.php and community-activity.php)
- **Multisite Plugin Migration**: All multisite functionality moved to extrachill-multisite plugin for network activation
- **Font File Cleanup**: Removed legacy font files for performance optimization
- **WooCommerce Template Router Support**: Added native WooCommerce template handling bypass
- **Search Plugin Integration**: Theme provides search header template and CSS, extrachill-search plugin provides main template via filter override
- **Native Pagination**: Custom pagination system replacing wp-pagenavi plugin
- **Plugin Migrations**: Newsletter, contact forms, Festival Wire, and multisite functionality moved to dedicated plugins
- **System Removals**: 15+ legacy files eliminated (event submission, location filtering, session tokens, override system)
- **Performance Optimization**: Conditional loading, memory optimization, plugin architecture integration
- **WordPress Standards**: Native multisite authentication, WordPress native template routing via `template_include` filter
- **View Count Tracking**: Universal post view counting with editor/admin exclusion
- **Permalink Optimization**: Category base rewriting for consistent multisite URL structures
- **Sticky Header Control**: `extrachill_enable_sticky_header` filter for plugin customization
- **Shared Components**: Reusable tab interface system with CSS and JavaScript

---

**Theme**: ExtraChill
**Author**: Chubes
**Version**: 1.0.0
**WordPress**: 5.0+
**License**: Proprietary