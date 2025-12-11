# ExtraChill WordPress Theme

A custom WordPress theme (v1.2.9) powering the Extra Chill Platform multisite network with 9 active interconnected sites (Blog ID 6 unused), featuring hook-based homepage content, community integrations, and multisite-aware navigation. Horoscope site is planned for future Blog ID 11.

> **Platform alignment**: Documentation reflects the live 1.2.9 release in `style.css` and must stay in lockstep with future releases.

## Overview

ExtraChill is a modern, performance-optimized WordPress theme (v1.2.9) designed specifically for the Extra Chill Platform multisite network. It serves as the frontend for all 9 active interconnected sites (Blog ID 6 unused) with docs.extrachill.com at Blog ID 10; horoscope.extrachill.com planned for future Blog ID 11:

- **extrachill.com** (Blog ID 1): Music journalism, artist features, and industry coverage
- **community.extrachill.com** (Blog ID 2): Community forums with bbPress integration
- **shop.extrachill.com** (Blog ID 3): E-commerce with WooCommerce integration
- **artist.extrachill.com** (Blog ID 4): Artist platform and profiles
- **chat.extrachill.com** (Blog ID 5): AI chatbot interface
- **events.extrachill.com** (Blog ID 7): Event calendar hub
- **stream.extrachill.com** (Blog ID 8): Live streaming platform
- **newsletter.extrachill.com** (Blog ID 9): Newsletter management and archive
- **docs.extrachill.com** (Blog ID 10): Documentation hub
- **extrachill.link**: Artist link pages (domain-mapped to artist.extrachill.com)

## Key Features

### 🎵 Music-Focused Content Management
- **Custom Taxonomies**: Artist, Venue, Festival, and Location organization with REST API support
- **Festival Wire Output**: ExtraChill News Wire plugin renders ticker/feed blocks through theme hooks; core theme only supplies shared CSS tokens
- **Homepage Content Delivery**: Front page is one container powered by `extrachill_homepage_content` (primary content) and `extrachill_after_homepage_content` (footer/CTA slot)
- **Search Integration**: Theme ships the search header template and CSS while the extrachill-search plugin owns the multisite loop, result badges, and aggregation logic
- **Share System**: Integrated share buttons with clipboard copy and social sharing functionality
- **Notice System**: Centralized user feedback system supporting multiple notices, anonymous user cookies, and action buttons

### 🚀 Performance Optimizations
- **Conditional Asset Loading**: CSS/JS load only when their contexts require them (archives, search, sidebar, shared tabs, footer stats, notices, share buttons, etc.)
- **Modular CSS Architecture**: Eleven page/component-specific stylesheets (`root`, `archive`, `single-post`, `nav`, `taxonomy-badges`, `editor-style`, `search`, `shared-tabs`, `share`, `sidebar`, `notice`)
- **Image Optimization**: Unused WordPress image sizes removed for leaner uploads
- **View Tracking**: Async REST-powered view counting with script enqueued only on public singular content
- **Multisite Optimization**: Direct `switch_to_blog()` access and cached lookups keep network features fast
- **Enhanced Pagination**: Array-based pagination support for custom queries with dynamic item labels

### 🎨 Modern Design System
- **CSS Variables**: Global design tokens in `assets/css/root.css`
- **Component Styles**: Dedicated files for navigation, badges, search, shared tabs, share buttons, sidebar widgets, notices, and editor styles (all depend on the root handle)
- **Responsive Design**: Mobile-first layouts, sticky header toggle, secondary header filter, network dropdown, and accessible navigation/flyout controls
- **Icon System**: QR code and download icons added to SVG sprite system

### 🤝 Community Integration
- **WordPress Multisite**: Theme powers 8 active network sites with shared routing and navigation patterns
- **Shared Community Activity Helper**: Centralized library (`inc/core/templates/community-activity.php`) exposes `extrachill_get_community_activity_items()` and `extrachill_render_community_activity()`
- **Community Data Source**: Queries community.extrachill.com (Blog ID 2) for bbPress topics/replies with 10-minute caching and renders default sidebar/homepage widgets
- **Activity Feeds**: Sidebar widget plus plugin-provided homepage blocks consume the same helper for consistent markup
- **Multisite Search**: Cross-site search via extrachill-search plugin with `extrachill_multisite_search()`
- **Profile URL Resolution**: extrachill-users plugin supplies `ec_get_user_profile_url()` for community links
- **Online Users Widget**: `extrachill_before_footer` renders online/total counts from extrachill-users data
- **Network Dropdown**: Integrated network site navigation dropdown
- **Graceful Degradation**: Hooks and function_exists checks keep templates stable if network plugins are inactive

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
    - **ExtraChill Multisite** (network-activated - core multisite functionality, cross-domain authentication)
    - **ExtraChill Search** (network-activated - cross-site search with site badges)
    - **ExtraChill Users** (network-activated - user profile URL resolution, avatar menu, team member management)
    - **ExtraChill News Wire** (Festival Wire functionality and ticker/feed blocks)
    - **ExtraChill Newsletter** (newsletter management and archive functionality)
    - **ExtraChill Contact** (contact form functionality)
    - **ExtraChill Events** (event calendar and management functionality)

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
# CSS files are in /assets/css/ (11 CSS files: root.css, archive.css, single-post.css, nav.css, taxonomy-badges.css, editor-style.css, search.css, shared-tabs.css, share.css, sidebar.css, notice.css)
# JavaScript files are in /assets/js/ (6 files: nav-menu.js, reading-progress.js, chill-custom.js, shared-tabs.js, view-tracking.js, share.js)
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
│   │   ├── archive.css         # Archive page styles
│   │   ├── single-post.css     # Single post styles
│   │   ├── nav.css             # Navigation styles
│   │   ├── taxonomy-badges.css # Taxonomy badge colors
│   │   ├── editor-style.css    # Block editor styles
│   │   ├── search.css          # Search results styles
│   │   ├── shared-tabs.css     # Shared tab interface styles
│   │   ├── share.css           # Social share buttons
│   │   ├── sidebar.css         # Sidebar components
│   │   └── notice.css          # Notice system styles
│   ├── js/                     # JavaScript files (6 files)
│   │   ├── nav-menu.js         # Navigation menu functionality
│   │   ├── reading-progress.js # Reading progress indicator
│   │   ├── chill-custom.js     # Custom functionality
│   │   ├── shared-tabs.js      # Shared tab interface
│   │   ├── view-tracking.js    # Async REST-powered view tracking
│   │   └── share.js            # Share button interactions
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
│   ├── header/                 # Header functionality (2 files)
│   │   ├── header-search.php
│   │   └── secondary-header.php
│   ├── home/                   # Homepage components (8 files: 1 + 7 templates)
│   │   └── templates/
│   │       └── front-page.php   # Single hook container
│   ├── sidebar/                # Sidebar functionality (2 files)
│   │   ├── recent-posts.php
│   │   └── community-activity.php
│   └── single/                 # Single post/page functionality (4 files)
│       ├── comments.php
│       ├── related-posts.php
│       ├── single-post.php
│       └── single-page.php
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
- **Plugin Override Support**: Filter hooks allow plugins to completely override template files at routing level
- **Supported Routes**: `extrachill_template_homepage`, `extrachill_template_single_post`, `extrachill_template_page` (only when no custom template assigned), `extrachill_template_archive`, `extrachill_template_search`, `extrachill_template_404`, `extrachill_template_fallback`
- **Performance Benefits**: Efficient routing while maintaining WordPress compatibility
- **Extensibility**: Filter system allows complete template customization

### Modular Design

The theme follows a modular architecture with clear separation of concerns:

- **Core WordPress functionality** in `/inc/core/` (8 core files + 2 subdirectories = 21 total files)
- **Shared template components** in `/inc/core/templates/` (11 reusable templates including 404.php, community-activity.php, and notices.php)
- **Custom embeds** in `/inc/core/editor/` (3 embed types: Bandcamp, Spotify, Instagram)
- **Archive functionality** in `/inc/archives/` (7 core files + search subdirectory with 1 file = 8 total files)
- **Search integration** in `/inc/archives/search/` (1 file: search-header.php used via extrachill_search_header hook by extrachill-search plugin)
- **Footer navigation** in `/inc/footer/` (4 footer files: main menu, bottom menu, back-to-home navigation, online users stats)
- **Header navigation system** in `/inc/header/` (4 navigation files including secondary header)
- **Homepage components** in `/inc/home/` (1 homepage file: front-page.php)
- **Sidebar functionality** in `/inc/sidebar/` (2 sidebar files)
- **Single post/page features** in `/inc/single/` (4 single files)
- **Total**: 48 PHP files in `/inc/` directory with 28 directly loaded in functions.php

### Hook-Based Menu System

The theme features a sophisticated hook-based menu system that replaces WordPress's native menu management:

- **Performance**: Hardcoded menus eliminate database queries for menu generation
- **Extensibility**: Plugins can hook into `extrachill_navigation_main_menu` and `extrachill_footer_main_content` to add menu items
- **Maintainability**: Menu content separated into focused template files (`footer-main-menu.php`)
- **Admin Cleanup**: WordPress menu management interface removed via `extrachill_remove_menu_admin_pages()`

The system uses action hooks registered in `inc/core/actions.php` to load hardcoded menu templates, allowing both performance optimization and plugin extensibility without the overhead of WordPress's menu system.

### Performance Features

  - **WordPress native routing**: Template routing via `template_include` filter in `inc/core/template-router.php`
  - **Modular asset loading**: CSS/JS loaded only when needed based on page context via `inc/core/assets.php`
  - **Native pagination system**: Lightweight WordPress pagination in `inc/core/templates/pagination.php` with array-based support
  - **Memory optimization**: Efficient resource usage tracking and admin style dequeuing
  - **Query optimization**: Direct database queries for multisite integration
  - **Asset optimization**: SVG support, emoji script removal, unnecessary image size removal
  - **Conditional loading**: WooCommerce styles, admin styles, and plugin styles loaded only when needed
  - **Shared tab components**: `shared-tabs.css`/`shared-tabs.js` registered centrally to keep accordion/tabs consistent across templates while letting plugins enqueue via filters
  - **View count tracking**: Universal post view counting system with editor/admin exclusion in `inc/core/view-counts.php`
  - **Permalink optimization**: Category base rewriting for consistent multisite URLs in `inc/core/rewrite.php`
  - **Notice system**: Efficient user feedback with cookie-based dismissal tracking
 
  ### Shared UI Components

  - **Shared tabs**: `inc/core/assets.php` registers `shared-tabs.css` and `shared-tabs.js`; templates can enqueue them via `extrachill_enqueue_shared_tabs()` or through plugin overrides while the JavaScript manages desktop/mobile layouts, hash updates, and custom event dispatching (`sharedTabActivated`)
  - **View tracking script**: `inc/core/assets.php` enqueues `view-tracking.js` only for public singular content, skipping editors and previewers, and localizes `ecViewTracking` with `postId` and the `extrachill/v1/analytics/view` REST endpoint for downstream analytics consumption
  - **Sidebar extension**: `sidebar.php` supports `extrachill_sidebar_content` filter for full replacements and `extrachill_sidebar_top/middle/bottom` actions for hook-based widgets like recent posts and community activity; `inc/core/assets.php` conditions `sidebar.css` only when those hooks render post-related content
  - **Share buttons**: `inc/core/templates/share.php` provides social sharing with clipboard copy and fallback support
  - **Notice system**: `inc/core/notices.php` enables multiple notices with cookie-based dismissal and action buttons
 
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
// Output homepage content via the single hook container
add_action('extrachill_homepage_content', 'custom_homepage_block', 20);

// Override page template (only when no custom page template assigned)
add_filter('extrachill_template_page', function($template) {
    return MY_PLUGIN_DIR . '/custom-page.php';
});

// Disable sticky header
add_filter('extrachill_enable_sticky_header', '__return_false');

// Append CTA below homepage content
add_action('extrachill_after_homepage_content', 'custom_homepage_cta', 15);

// Display notices
do_action('extrachill_notices');

// Customize share button
extrachill_share_button(array(
    'share_url' => 'https://example.com',
    'share_title' => 'Custom Title'
));
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



## Support and Contributing

### Getting Help

- Check the [AGENTS.md](AGENTS.md) file for detailed technical information
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

See [docs/CHANGELOG.md](docs/CHANGELOG.md) for full version history.

---

**Theme**: ExtraChill
**Author**: Chubes
**Version**: 1.2.9
**WordPress**: 5.0+
**License**: Proprietary