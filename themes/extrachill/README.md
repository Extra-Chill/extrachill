# ExtraChill WordPress Theme

A custom WordPress theme powering the ExtraChill music ecosystem, featuring blog content, community integration, festival coverage, and e-commerce functionality.

## Overview

ExtraChill is a modern, performance-optimized WordPress theme designed specifically for the independent music community. It serves as the frontend for a comprehensive music ecosystem that includes:

- **Main Blog**: Music journalism, artist features, and industry coverage
- **Community Forum**: Integration with bbPress for user discussions
- **Festival Wire**: Homepage ticker integration (functionality provided by ExtraChill News Wire plugin)
- **Merch Store**: WooCommerce integration for merchandise sales
- **Event Listings**: Custom event scraping and management system

## Key Features

### 🎵 Music-Focused Content Management
- **Custom Taxonomies**: Artist, Venue, Festival, and Location organization
- **Festival Wire Integration**: Homepage ticker display for festival coverage (requires ExtraChill News Wire plugin)
- **Newsletter Integration**: Newsletter functionality provided by ExtraChill Newsletter Plugin
- **Event Scraping**: Automated event data collection from multiple venues

### 🚀 Performance Optimizations
- **Conditional Asset Loading**: CSS/JS loaded only when needed
- **WooCommerce Optimization**: E-commerce features load only on relevant pages
- **Image Optimization**: Unnecessary WordPress image sizes removed
- **Memory Management**: Efficient resource usage with memory tracking

### 🎨 Modern Design System
- **Modular CSS Architecture**: Component-based styling with CSS custom properties
- **Dark Mode Ready**: CSS variables support automatic theme switching
- **Responsive Design**: Mobile-first approach with flexible layouts
- **Custom Typography**: Local web fonts for optimal performance

### 🤝 Community Integration
- **bbPress Forum Integration**: Seamless community discussions with multisite support
- **WordPress Multisite**: Native cross-domain authentication and user management
- **Forum Search**: Multisite-native forum search functionality (`ec_fetch_forum_results_multisite()`)
- **Activity Feeds**: Recent community activity integration via multisite functions (`ec_fetch_recent_activity_multisite()`)
- **User Details**: Native WordPress authentication (`preload_user_details()` replaces session tokens)
- **Upvote System**: Community-driven content engagement with multisite user sessions

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

3. **Configure required plugins** (optional but recommended):
   - bbPress (for community features)
   - WooCommerce (for store functionality)
   - Co-Authors Plus (for multi-author posts)
   - ExtraChill News Wire (for Festival Wire functionality)

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
# CSS files are in /css/
# JavaScript files are in /js/
# PHP files use standard WordPress structure

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
├── css/                    # Modular CSS files
│   ├── root.css           # CSS custom properties
│   ├── home.css           # Homepage styles
│   ├── archive.css        # Archive page styles
│   └── single-post.css    # Single post styles
├── inc/                   # Modular PHP functionality
│   ├── admin/             # Admin functionality
│   ├── community/         # Forum integration with multisite functions
│   │   ├── multisite-forum-search.php     # Multisite forum search functions
│   │   ├── recent-activity-feed.php       # Multisite activity feed functions
│   │   └── community-session.php          # Native WordPress authentication
│   ├── core/              # WordPress core features
│   ├── home/              # Homepage components (includes Festival Wire ticker)
│   └── woocommerce/       # E-commerce integration
├── js/                    # JavaScript files
├── chill-events/          # Event scraping system
├── page-templates/        # Custom page templates
└── woocommerce/           # WooCommerce template overrides
```

### Adding New Features

1. **Create modular files** in appropriate `/inc/` subdirectory
2. **Include in functions.php** with proper dependencies
3. **Add styles** in dedicated CSS file with conditional loading
4. **Test thoroughly** with WordPress debugging enabled
5. **For production**: Run `./build.sh` to create deployable package

### CSS Development

- **Root variables** go in `css/root.css`
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

- **Core WordPress functionality** in `/inc/core/`
- **Admin features** in `/inc/admin/`
- **Community features** in `/inc/community/`
- **Custom post types** in dedicated directories
- **WooCommerce integration** in `/inc/woocommerce/`

### Performance Features

- **Conditional WooCommerce loading**: Only loads when products are present
- **Dynamic asset loading**: CSS/JS loaded based on page context
- **Memory optimization**: Efficient resource usage tracking
- **Query optimization**: Streamlined database queries

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
3. **Custom CSS files** in `/css/` directory

### Hooks and Filters

The theme provides extensive hooks for customization:

```php
// Modify event scraper data
add_filter('extrachill_event_data', 'custom_event_modification');

// Customize festival wire ticker display
add_filter('extrachill_festival_wire_ticker', 'custom_ticker_display');

// Modify community integration
add_action('extrachill_community_init', 'custom_community_setup');
```

## Troubleshooting

### Common Issues

**WooCommerce not loading properly**:
- Check that WooCommerce plugin is active
- Verify theme support is enabled
- Clear any caching plugins

**Festival Wire not displaying**:
- Ensure ExtraChill News Wire plugin is installed and activated
- Check that Festival Wire posts exist in WordPress admin
- Verify homepage ticker functionality

**Community features not working**:
- Ensure bbPress is installed and configured
- Check user permissions
- Verify community integration files

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

### Version 69.57
- Complete ColorMag Pro conversion
- WooCommerce performance optimization
- Modular CSS architecture implementation
- Community integration enhancements
- Festival Wire migration to standalone plugin
- Performance optimizations and memory management
- WordPress multisite integration for cross-domain authentication
- Native multisite functions replace REST API calls for better performance

---

**Theme**: ExtraChill
**Author**: Chubes
**Version**: 69.57
**WordPress**: 5.0+
**License**: Proprietary