# ExtraChill WordPress Theme

A custom WordPress theme powering the ExtraChill music ecosystem, featuring blog content, community integration, festival coverage, and e-commerce functionality.

## Overview

ExtraChill is a modern, performance-optimized WordPress theme designed specifically for the independent music community. It serves as the frontend for a comprehensive music ecosystem that includes:

- **Main Blog**: Music journalism, artist features, and industry coverage
- **Community Forum**: Integration with bbPress for user discussions
- **Festival Wire**: Homepage ticker integration (functionality provided by ExtraChill News Wire plugin)
- **Merch Store**: WooCommerce integration for merchandise sales

## Key Features

### 🎵 Music-Focused Content Management
- **Custom Taxonomies**: Artist, Venue, and Festival organization with REST API support
- **Festival Wire Integration**: Homepage ticker display for festival coverage (requires ExtraChill News Wire plugin)
- **Newsletter Integration**: Newsletter functionality provided by ExtraChill Newsletter Plugin
- **Contact Forms**: Contact form functionality provided by ExtraChill Contact Plugin

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
- **Component Styles**: Dedicated files for badges (`badge-colors.css`), editor styles (`editor-style.css`)

### 🤝 Community Integration
- **WordPress Multisite**: Native cross-domain authentication and user management
- **Forum Search**: Real-time search across multisite network via `ec_fetch_forum_results_multisite()` (hardcoded blog ID 2)
- **Activity Feeds**: Cross-site activity integration via `ec_fetch_recent_activity_multisite()`
- **Ad-Free License**: Cross-site license validation via `is_user_ad_free()` (hardcoded blog ID 3)
- **Comment Author Links**: Cross-site comment author linking for community integration

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
   - ExtraChill News Wire (for Festival Wire functionality)
   - ExtraChill Newsletter (for newsletter functionality)
   - ExtraChill Contact (for contact form functionality)

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
# CSS files are in /assets/css/ (root.css, home.css, archive.css, single-post.css, nav.css, badge-colors.css, editor-style.css)
# JavaScript files are in /assets/js/
# PHP files use modular include structure in /inc/ directory

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

# Output will be in /dist/ directory:
# - dist/extrachill/ (clean production directory)
# - dist/extrachill.zip (deployable ZIP file)
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
├── assets/                     # Theme assets directory
│   ├── css/                    # Modular CSS files (7 files)
│   │   ├── root.css            # CSS custom properties
│   │   ├── home.css            # Homepage styles
│   │   ├── archive.css         # Archive page styles
│   │   ├── single-post.css     # Single post styles
│   │   ├── nav.css             # Navigation styles
│   │   ├── badge-colors.css    # Taxonomy badge colors
│   │   └── editor-style.css    # Block editor styles
│   └── fonts/                  # Local web fonts
├── inc/                        # Modular PHP functionality (44+ files)
│   ├── admin/                  # Admin functionality (3 files)
│   │   ├── log-404-errors.php
│   │   ├── tag-migration-admin.php
│   │   └── extrachill-customizer.php
│   ├── archives/               # Archive page functionality (4 files)
│   │   ├── archive.php
│   │   ├── archive-child-terms-dropdown.php
│   │   ├── archive-custom-sorting.php
│   │   └── post-card.php
│   ├── core/                   # Core WordPress features
│   │   ├── multisite/          # Cross-site integration (4 files)
│   │   │   ├── multisite-search.php
│   │   │   ├── recent-activity-feed.php
│   │   │   ├── ad-free-license.php
│   │   │   └── comment-author-links.php
│   │   ├── templates/          # Shared template components (8 files)
│   │   │   ├── post-meta.php
│   │   │   ├── pagination.php
│   │   │   ├── no-results.php
│   │   │   ├── share.php
│   │   │   ├── social-links.php
│   │   │   ├── taxonomy-badges.php
│   │   │   ├── breadcrumbs.php
│   │   │   └── searchform.php
│   │   ├── editor/             # Custom embeds (3 files)
│   │   │   ├── bandcamp-embeds.php
│   │   │   ├── instagram-embeds.php
│   │   │   └── spotify-embeds.php
│   │   ├── assets.php          # Asset management
│   │   ├── template-overrides.php
│   │   ├── custom-taxonomies.php
│   │   ├── rewrite-rules.php
│   │   └── yoast-stuff.php
│   ├── header/                 # Navigation functionality (2 files)
│   │   ├── walker.php
│   │   └── navigation-menu.php
│   ├── home/                   # Homepage components (8+ files)
│   │   ├── homepage-queries.php
│   │   └── templates/          # Homepage template sections
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

### Modular Design

The theme follows a modular architecture with clear separation of concerns:

- **Core WordPress functionality** in `/inc/core/` (7 core files + 3 subdirectories)
- **Shared template components** in `/inc/core/templates/` (8 reusable templates)
- **Multisite integration** in `/inc/core/multisite/` (4 cross-site files)
- **Custom embeds** in `/inc/core/editor/` (3 embed types)
- **Admin features** in `/inc/admin/` (3 admin files)
- **Archive functionality** in `/inc/archives/` (4 archive files)
- **Navigation system** in `/inc/header/` (2 navigation files)
- **Homepage components** in `/inc/home/` (8+ homepage files)
- **Sidebar functionality** in `/inc/sidebar/` (2 sidebar files)
- **Single post/page features** in `/inc/single/` (4 single files)

### Performance Features

- **Modular asset loading**: CSS/JS loaded only when needed based on page context via `inc/core/assets.php`
- **Native pagination system**: Lightweight WordPress pagination in `inc/core/templates/pagination.php`
- **Memory optimization**: Efficient resource usage tracking and admin style dequeuing
- **Query optimization**: Direct database queries for multisite integration (hardcoded blog IDs)
- **Asset optimization**: SVG support, emoji script removal, unnecessary image size removal
- **Conditional loading**: WooCommerce styles, admin styles, and plugin styles loaded only when needed

### Security Implementation

- **Output escaping**: All output properly escaped
- **Input sanitization**: All inputs sanitized
- **Nonce verification**: AJAX requests protected
- **Capability checks**: Admin functions secured
- **External link security**: Automatic security attributes

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

// Modify homepage content structure
add_filter('extrachill_homepage_content', 'custom_homepage_layout');

// Add custom functionality to final homepage section
add_action('extrachill_home_final_left', 'custom_final_section');
```

## Troubleshooting

### Common Issues

**Festival Wire not displaying**:
- Ensure ExtraChill News Wire plugin is installed and activated
- Check that Festival Wire posts exist in WordPress admin
- Verify plugin hook integration with theme

**Multisite search not working**:
- Check that multisite is properly configured
- Verify blog IDs are correct in multisite functions
- Ensure cross-site database access permissions are set

### Debug Mode

Enable debugging for development:

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('SCRIPT_DEBUG', true);
```

## Recent Changes (v69.57+)

### New Modular Architecture
- **Core Templates Directory**: Created `/inc/core/templates/` for 8 shared template components
- **Sidebar Directory**: Created `/inc/sidebar/` for sidebar-specific functionality
- **Multisite Directory**: Organized `/inc/core/multisite/` for 4 cross-site functionality files
- **Native Pagination System**: Replaced wp-pagenavi plugin with lightweight, native WordPress pagination
  - Located at `inc/core/templates/pagination.php`
  - Professional post count display with context-aware navigation
  - Proper URL parameter handling for filtered views
  - Context-specific styling support

### System Removals (15+ Files Deleted)
- **Event Submission System**: Complete removal of event submission functionality
- **Location System**: Complete elimination of unused location browsing functionality
- **Session Token System**: Completely removed in favor of native WordPress multisite authentication
- **WooCommerce Files**: All WooCommerce templates and CSS removed, moved to ExtraChill Shop plugin
- **Community Files**: Removed community-comments.php, community-session.php, extrachill-upvotes.php, forum-search.php, multisite-forum-search.php
- **Legacy Templates**: Removed content-page.php, content-single.php, content.php, comments.php, no-results.php, page.php, search.php, searchform.php, single.php
- **Legacy CSS**: Removed all-locations.css, woocommerce.css
- **Legacy PHP**: Removed breadcrumbs.php, recent-posts-in-sidebar.php, location-filter.php, contextual-search-excerpt.php

### Performance Improvements
- **Asset Directory Migration**: Moved all assets from `css/` and `js/` to `assets/css/` and `assets/js/`
- **CSS Modularization**: 7 dedicated CSS files with conditional loading:
  - `assets/css/root.css` - CSS custom properties
  - `assets/css/home.css` - Homepage styles
  - `assets/css/archive.css` - Archive/search styles
  - `assets/css/single-post.css` - Single post styles
  - `assets/css/nav.css` - Navigation styles
  - `assets/css/badge-colors.css` - Taxonomy badge colors
  - `assets/css/editor-style.css` - Block editor styles
- **Streamlined asset loading**: Conditional CSS/JS enqueuing based on page context via `inc/core/assets.php`
- **Memory optimization**: Efficient resource management through selective loading and admin style dequeuing
- **Multisite optimization**: Direct database queries with hardcoded blog IDs replace REST API calls
- **Template consolidation**: 44+ modular PHP files replace monolithic template structure

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

### Version 69.57+
- **Modular Architecture**: 44+ PHP files organized in 7 directories with clear separation of concerns
- **Asset Migration**: Complete move from legacy `css/` and `js/` to `assets/css/` and `assets/js/`
- **Template System**: 8 shared template components in `/inc/core/templates/`
- **Multisite Integration**: 4 cross-site files in `/inc/core/multisite/` with hardcoded blog IDs
- **Native Pagination**: Custom pagination system replacing wp-pagenavi plugin
- **Plugin Migrations**: Newsletter, contact forms, Festival Wire moved to dedicated plugins
- **System Removals**: 15+ legacy files eliminated (event submission, location filtering, session tokens)
- **Performance Optimization**: Conditional loading, memory optimization, direct database queries
- **WordPress Standards**: Native multisite authentication, proper template hierarchy

---

**Theme**: ExtraChill
**Author**: Chubes
**Version**: 69.57
**WordPress**: 5.0+
**License**: Proprietary