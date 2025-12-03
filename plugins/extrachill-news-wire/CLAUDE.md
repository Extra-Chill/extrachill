# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The **Extra Chill News Wire Plugin** is a WordPress plugin that provides festival and music news wire functionality with custom post types, AJAX features, and migration tools. This plugin was extracted from the Extra Chill theme to provide dedicated news wire management as a standalone component.

## Key Features

### Custom Post Type System
- **Festival Wire Post Type**: Dedicated `festival_wire` custom post type for festival news and coverage
- **Archive Support**: Custom archive pages with filtering and pagination at `/festival_wire/`
- **Single Post Templates**: Specialized display templates for individual festival wire posts
- **REST API Support**: Full REST API integration for potential mobile app consumption

### Taxonomy Integration
- **Festival Taxonomy**: Primary festival classification system
- **Category Support**: Standard WordPress categories for content organization
- **Data Source Taxonomy**: Content source tracking and attribution

### AJAX-Powered Features
- **Real-time Content Loading**: Dynamic content updates without page refresh
- **Infinite Scroll**: Seamless content pagination for improved user experience
- **Tip Submission System**: Community-driven content submission with Turnstile verification

### Administrative Tools
- **Tag Migration**: One-time migration tool converting post tags to festival taxonomy
- **Author Migration**: Bulk author reassignment for all Festival Wire posts
- **Migration Reporting**: Detailed migration statistics and confirmation

## Architecture Standards

### Plugin Structure
```
extrachill-news-wire/
├── extrachill-news-wire.php     # Main plugin file
├── includes/                    # Core functionality
│   ├── festival-wire-post-type.php       # Custom post type registration
│   ├── festival-wire-ajax.php            # AJAX handlers
│   ├── festival-wire-query-filters.php   # Query modifications
│   └── festival-tip-form.php             # Tip submission system
├── templates/                   # Template files
│   ├── archive-festival_wire.php         # Archive template
│   ├── single-festival_wire.php          # Single post template
│   └── content-card.php                  # Content card component
├── assets/                      # CSS/JS assets
│   ├── festival-wire.css        # Plugin styles
│   └── festival-wire.js         # AJAX functionality
└── build.sh                     # Production build script
```

### Development Standards
- **WordPress Hooks**: Extensive use of actions/filters for extensibility
- **Security Implementation**: Nonces, sanitization, escaping for all user interactions
- **Template System**: Plugin templates override theme templates via WordPress template hierarchy
- **Asset Management**: Conditional loading with `filemtime()` versioning for cache busting

## Development Commands

### Build and Deployment
```bash
# Create production-ready ZIP package
./build.sh

# Output: /build/extrachill-news-wire/ directory and /build/extrachill-news-wire.zip file
```

**Universal Build Script**: Symlinked to shared build script at `../../.github/build.sh`

The build script automatically:
- Auto-detects plugin from `Plugin Name:` header
- Extracts version from main plugin file for validation and logging
- Installs production dependencies: `composer install --no-dev` (if composer.json exists)
- Excludes development files via `.buildignore` rsync patterns
- Validates plugin structure and required files
- Creates both clean directory and non-versioned ZIP for WordPress deployment
- Restores development dependencies after build

## Core Functionality

### Custom Post Type Implementation
- **Post Type**: `festival_wire` with full WordPress editor support
- **Public Access**: Publicly queryable with archive and single page support
- **Capabilities**: Standard post capabilities for content management
- **Features**: Supports title, editor, author, thumbnail, excerpt, revisions

### Template Override System
The plugin uses WordPress template hierarchy override:
- Plugin templates in `/templates/` directory take precedence over theme templates
- Automatic template loading via `template_include` filter
- Maintains theme compatibility through proper hook usage

### AJAX System Architecture
- **Turnstile Integration**: Cloudflare Turnstile verification for tip submissions
- **Real-time Loading**: Dynamic content loading via WordPress AJAX endpoints
- **Security Implementation**: Proper nonce verification and capability checks
- **Response Handling**: Standardized JSON responses with error handling

### Migration Tools
Access via **Tools > Festival Wire Migration**:
- **Tag to Festival Migration**: Converts existing post tags to Festival taxonomy
- **Author Migration**: Bulk reassignment of Festival Wire post authors
- **One-time Operations**: Migration tools designed for single-use conversion
- **Detailed Reporting**: Statistics and confirmation for all migration operations

## Integration Patterns

### Theme Integration
- **Homepage Ticker**: Compatible with theme homepage ticker widgets
- **Template Compatibility**: Works with any WordPress theme via template override
- **Asset Loading**: Plugin assets load conditionally on relevant pages

### Cross-Plugin Compatibility
- **Extra Chill Theme Integration**: Seamless integration with main theme components
- **bbPress Compatibility**: Maintains forum integration patterns
- **Multisite Support**: Compatible with WordPress multisite architecture

## WordPress Standards Compliance

### Security Practices
- **Nonce Verification**: All forms and AJAX requests use WordPress nonces
- **Input Sanitization**: User input sanitized with appropriate WordPress functions
- **Output Escaping**: All output escaped via `esc_html()`, `esc_attr()`, `esc_url()`
- **Capability Checks**: Admin functionality requires proper user permissions

### Code Organization
- **WordPress Hooks**: Uses standard WordPress action and filter system
- **Template Hierarchy**: Follows WordPress template loading conventions
- **Internationalization**: Proper text domain usage for translation readiness
- **Documentation**: Inline documentation for technical implementation details

## Build Process

The build system creates production-ready WordPress plugin packages:

1. **Version Extraction**: Automatically reads version from plugin header
2. **File Exclusion**: Uses `.buildignore` patterns to exclude development files
3. **Structure Validation**: Ensures all required plugin files are present
4. **Production Optimization**: Clean directory structure for deployment
5. **ZIP Creation**: Generates non-versioned ZIP file for WordPress installation

Essential files for plugin functionality:
- Main plugin file with proper WordPress headers
- `/includes/` directory with all PHP modules
- `/templates/` directory with template files
- `/assets/` directory with CSS/JS files

## Dependencies

### Required
- **WordPress**: 5.0+
- **PHP**: 7.4+

### Optional
- **Cloudflare Turnstile**: For tip submission verification
- **jQuery**: Included with WordPress for AJAX functionality

This architecture enables dedicated festival news management while maintaining seamless integration with the broader Extra Chill ecosystem.