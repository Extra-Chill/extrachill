# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The **ExtraChill Newsletter Plugin** is a WordPress plugin that provides complete newsletter management with Sendy integration. It handles newsletter creation, subscription forms, campaign management, and email template generation for the ExtraChill platform.

## Common Development Commands

### Building and Deployment
```bash
# Create production-ready ZIP package
./build.sh

# Output: dist/extrachill-newsletter-{version}.zip
```

The build script automatically:
- Extracts version from main plugin file
- Copies files excluding items in `.buildignore`
- Validates plugin structure and required files
- Creates optimized ZIP for WordPress deployment

## Plugin Architecture

### Modular Structure
The plugin follows a modular architecture with functionality split across focused include files:

- **extrachill-newsletter.php**: Main plugin file with constants, asset loading, template overrides, and hook registration
- **includes/newsletter-post-type.php**: Custom post type registration, meta boxes, and admin interface
- **includes/newsletter-sendy-integration.php**: Sendy API integration for campaigns and subscriptions
- **includes/newsletter-ajax-handlers.php**: All AJAX endpoints for forms and admin actions
- **includes/newsletter-hooks.php**: WordPress hook integrations and theme connectivity
- **includes/newsletter-popup.php**: Newsletter popup functionality and modal system
- **includes/newsletter-admin.php**: Admin settings page and configuration management
- **templates/**: Template files that override WordPress theme templates

### Key Integration Patterns

#### Template Override System
The plugin uses WordPress template hierarchy override with `template_include` filter:
- Plugin templates in `/templates/` directory take precedence
- Theme templates serve as fallback
- Conditional template loading based on post type and context

#### Hook-Based Theme Integration
The plugin integrates with themes via action hooks:
- `extrachill_homepage_newsletter_section` - Homepage newsletter display
- `extrachill_navigation_before_social_links` - Navigation menu subscription form
- Uses `wp_nav_menu_items` filter for navigation integration

#### Asset Loading Strategy
- **Conditional CSS Loading**: Only on newsletter pages (archive, single, homepage)
- **Global JavaScript**: Loaded site-wide due to navigation subscription form
- **AJAX Localization**: Provides nonces and endpoints for all forms
- **File-based Versioning**: Uses `filemtime()` for cache busting

### Sendy Integration Architecture

#### API Configuration
- Settings stored in WordPress options (`extrachill_newsletter_settings`)
- Multiple list IDs for different subscription contexts (archive, homepage, popup, navigation)
- Centralized configuration via `get_sendy_config()` function

#### Campaign Management
- Meta box integration in newsletter edit screen
- "Push to Sendy" functionality creates/updates campaigns
- HTML template generation from WordPress post content
- Automatic campaign URL and subject line handling

#### Subscription Handling
Multiple subscription forms with dedicated AJAX handlers:
- Archive page subscription (`submit_newsletter_form`)
- Homepage subscription (`subscribe_to_sendy_home`)
- Navigation subscription (`subscribe_to_sendy`)
- Popup subscription (`submit_newsletter_popup_form`)
- Shortcode forms (`submit_newsletter_shortcode_form`)

### Custom Post Type Implementation
- **Post Type**: `newsletter` with full WordPress features
- **Archive Support**: Custom archive with subscription form
- **Template System**: Plugin templates override theme templates
- **REST API**: Enabled for potential mobile/API integration
- **Admin Interface**: Custom meta boxes for Sendy campaign management

### Security Implementation
- **Nonce Verification**: All AJAX requests use WordPress nonces
- **Capability Checks**: Admin functionality requires proper permissions
- **Input Sanitization**: User input sanitized before processing
- **Direct Access Prevention**: All files check for `ABSPATH` constant

## Development Guidelines

### File Organization
- Main plugin file handles core WordPress integration (hooks, templates, assets)
- Include files handle specific functionality domains
- Templates provide theme-independent display logic
- Assets directory contains compiled CSS/JS

### Template Development
When modifying templates:
- Templates use plugin's template directory as primary
- Follow WordPress template hierarchy conventions
- Maintain theme compatibility through proper hook usage
- Use consistent CSS classes for styling integration

### AJAX Development
When adding new AJAX functionality:
- Register handlers in `newsletter-ajax-handlers.php`
- Use proper nonce verification for security
- Follow existing response patterns (`wp_send_json_success`/`wp_send_json_error`)
- Provide user feedback through JavaScript responses

### Sendy Integration
When modifying Sendy integration:
- All API calls go through centralized functions
- Use `get_sendy_config()` for configuration access
- Handle API errors gracefully with user feedback
- Maintain subscription context separation (different list IDs)

## Build Process

The build process creates production-ready WordPress plugin packages:

1. **Version Extraction**: Automatically reads version from plugin header
2. **File Exclusion**: Uses `.buildignore` patterns to exclude development files
3. **Structure Validation**: Ensures all required plugin files are present
4. **Production Optimization**: Removes development files and creates clean structure
5. **ZIP Creation**: Generates versioned ZIP file for WordPress deployment

Essential files for plugin functionality:
- Main plugin file with proper WordPress headers
- `/includes/` directory with all PHP modules
- `/templates/` directory with template files
- `/assets/` directory with CSS/JS files

This architecture enables rapid development while maintaining WordPress best practices for security, performance, and extensibility.