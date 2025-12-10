# Extra Chill Blog Plugin

WordPress plugin providing blog-specific functionality for extrachill.com (Blog ID 1). Handles homepage customizations, secondary header navigation, and content-specific templates.

## Overview

**Plugin Name**: Extra Chill Blog
**Version**: 0.2.3
**Network**: false (site-activated on Blog ID 1 only)
**Purpose**: Blog-specific homepage, navigation, and archive functionality for the main Extra Chill content site

## Core Features

### Homepage System
- Custom homepage templates with multiple content sections
- Hero section with featured imagery
- Recent posts display
- About section integration
- Extra Chill Link promo section (marketing for artist link pages at extrachill.link)
- 3x3 content grid for featured posts

### Navigation
- Secondary header navigation specific to blog site
- Hook-based navigation architecture compatible with extrachill theme

### Archive System
- Blog archive template override and routing
- Category and taxonomy-specific archive handling
- Template hierarchy integration with theme

## Architecture

### File Organization
- **extrachill-blog.php** - Main plugin file with initialization
- **inc/home/** - Homepage system (hooks, queries, templates)
- **inc/core/** - Core functionality (navigation)
- **inc/archive/** - Archive template routing

### Loading Pattern
```php
// Direct require_once includes in main plugin file
require_once plugin_dir_path(__FILE__) . 'inc/home/homepage-hooks.php';
require_once plugin_dir_path(__FILE__) . 'inc/core/nav.php';
require_once plugin_dir_path(__FILE__) . 'inc/archive/blog-archive-routing.php';
```

## Integration Points

### extrachill.link Marketing
- **Promo Section**: `inc/home/templates/section-extrachill-link.php`
- **Purpose**: Promotes artist link pages at extrachill.link
- **CTA**: Links to https://extrachill.link/join for artist acquisition
- **Integration**: Rendered on homepage via homepage hooks

### Theme Integration
- Works with extrachill theme for homepage rendering
- Uses theme hooks for template integration
- CSS custom properties from theme `root.css`

### Multisite Activation
- Site-activated on Blog ID 1 only
- Not required on other network sites
- Independent of other site-specific plugins

## Development Standards

### WordPress Standards
- Follows WordPress plugin development best practices
- Compatible with multisite architecture
- Security-first with proper escaping and sanitization

### Code Organization
- Single responsibility principle per file
- Procedural code organization with direct includes
- No build system or transpilers required

## Build & Deployment

```bash
# Build production package
./build.sh

# Output: build/extrachill-blog.zip
```

All builds use shared `/.github/build.sh` via symlink and exclude development files (vendor/, tests/, docs/, etc.).

## Related Documentation

- **Root AGENTS.md**: Platform-wide architectural patterns
- **extrachill-plugins/AGENTS.md**: Plugin directory overview
- **extrachill/AGENTS.md**: Theme architecture and integration
