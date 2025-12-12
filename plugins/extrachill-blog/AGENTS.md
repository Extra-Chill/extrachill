# Extra Chill Blog Plugin

WordPress plugin providing blog-specific functionality for extrachill.com (Blog ID 1). Handles homepage customizations, secondary header navigation, and content-specific templates.

## Overview

**Plugin Name**: Extra Chill Blog
**Version**: 0.3.0
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

### Gutenberg Blocks
- **Band Name Generator**: Interactive block for generating random band names with genre selection
- **Rapper Name Generator**: Interactive block for generating random rapper names
- **Image Voting**: Community voting block with email capture functionality
- **Trivia**: Interactive trivia questions with multiple choice answers and automatic scoring
- **AI Adventure**: Text-based adventure game with branching paths and prompt-based storytelling
- **AI Adventure Path**: Path component for structuring adventure game narratives
- **AI Adventure Step**: Step component for individual adventure game interactions

All blocks use the `extrachill/` namespace and are built with @wordpress/scripts. Block versions are independently managed (currently v1.1.0) separate from plugin versioning.

### Navigation
- Secondary header navigation specific to blog site
- Hook-based navigation architecture compatible with extrachill theme

### Archive System
- Blog archive template override and routing
- Category and taxonomy-specific archive handling
- Template hierarchy integration with theme
- Breadcrumb integration for blog archive with network dropdown support

## Architecture

### File Organization
- **extrachill-blog.php** - Main plugin file with block registration and initialization
- **src/blocks/** - Gutenberg block source files (JavaScript, PHP, SCSS)
- **inc/home/** - Homepage system (hooks, queries, templates)
- **inc/core/** - Core functionality (navigation)
- **inc/archive/** - Archive template routing and breadcrumbs

### Loading Pattern
```php
// Direct require_once includes in main plugin file
require_once plugin_dir_path(__FILE__) . 'inc/home/homepage-hooks.php';
require_once plugin_dir_path(__FILE__) . 'inc/core/nav.php';
require_once plugin_dir_path(__FILE__) . 'inc/archive/blog-archive-routing.php';
require_once plugin_dir_path(__FILE__) . 'inc/archive/breadcrumbs.php';
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

The build process uses the local `build.sh` script which:
1. Installs production Composer dependencies (`composer install --no-dev`)
2. Compiles Gutenberg blocks using `@wordpress/scripts` (`npm run build`)
3. Creates compiled assets in `build/blocks/` directory
4. Packages everything into a production ZIP excluding development files via `.buildignore`

### Block Asset Management
Blocks use separate source and build directories:
- **Source**: `src/blocks/` - Contains block.json, JS, SCSS, and PHP files
- **Build**: `build/blocks/` - Contains compiled CSS/JS and copied PHP files (created by `npm run build`)
- **Registration**: Blocks are registered from `build/blocks/` in production, `src/blocks/` in development

### Development Workflow
```bash
# Start development server with hot reloading
npm run start

# Build blocks for testing
npm run build
```

## Related Documentation

- **Root AGENTS.md**: Platform-wide architectural patterns
- **extrachill-plugins/AGENTS.md**: Plugin directory overview
- **extrachill/AGENTS.md**: Theme architecture and integration
