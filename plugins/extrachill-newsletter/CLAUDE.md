# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The **ExtraChill Newsletter Plugin** is a WordPress plugin that provides complete newsletter management with Sendy integration. It handles newsletter creation, subscription forms, campaign management, and email template generation for the ExtraChill platform.

## Common Development Commands

### Building and Deployment
```bash
# Create production-ready ZIP package
./build.sh

# Output: /build/extrachill-newsletter/ directory and /build/extrachill-newsletter.zip file
```

**Universal Build Script**: Symlinked to shared build script at `../../.github/build.sh`

The build script automatically:
- Auto-detects plugin from `Plugin Name:` header
- Extracts version from main plugin file for validation and logging
- Installs production dependencies: `composer install --no-dev` (if composer.json exists)
- Copies files excluding items in `.buildignore` using rsync
- Validates plugin structure and required files
- Creates both clean directory and non-versioned ZIP for WordPress deployment
- Restores development dependencies after build

## Plugin Architecture

### Network-Activated Self-Contained Plugin

**Network Activation**: `Network: true` - Activated across entire WordPress multisite network

**Architecture Pattern**: Self-contained with conditional loading based on site context

This plugin provides complete newsletter functionality with network-wide subscription forms and centralized management at newsletter.extrachill.com.

**Integration System**:
- Plugins register newsletter contexts declaratively via `newsletter_form_integrations` filter
- Admin configures each integration (enable toggle + Sendy list ID) via Newsletter → Settings (newsletter site only)
- All settings stored network-wide in `get_site_option('extrachill_newsletter_settings')`
- Zero hardcoded credentials - all API keys and list IDs configurable through admin UI
- Subscription bridge function `extrachill_multisite_subscribe()` available network-wide
- Dynamic action hooks created for each integration: `newsletter_display_{context}`
- Supports multiple contexts: navigation, homepage, popup, archive, content, footer, festival_wire_tip

### Conditional Loading Pattern

The plugin uses `is_newsletter_site()` helper to conditionally load features:

**Newsletter Site Only** (newsletter.extrachill.com):
- Newsletter custom post type (`inc/newsletter-post-type.php`)
- Sendy campaign integration (`inc/newsletter-sendy-integration.php`)
- Admin settings page (`inc/admin/newsletter-settings.php`)
- Newsletter popup (`inc/newsletter-popup.php`)
- Homepage as archive template override

**Network-Wide** (all sites):
- Subscription functions (`inc/newsletter-subscribe.php`)
- AJAX handlers for forms (`inc/newsletter-ajax-handlers.php`)
- Hook integrations for form display (`inc/newsletter-hooks.php`)
- Asset enqueuing

### Modular Structure

The plugin follows a modular architecture with conditional loading:

- **extrachill-newsletter.php**: Main plugin file with network activation, conditional loading, constants, and form display functions
- **inc/newsletter-subscribe.php**: Network-wide subscription functions and Sendy API configuration
- **inc/newsletter-post-type.php**: Custom post type registration (newsletter site only)
- **inc/newsletter-sendy-integration.php**: Sendy API integration for campaigns (newsletter site only)
- **inc/newsletter-ajax-handlers.php**: All AJAX endpoints for forms with security and rate limiting (network-wide)
- **inc/newsletter-hooks.php**: WordPress hook integrations and theme connectivity (network-wide)
- **inc/newsletter-popup.php**: Newsletter popup functionality (newsletter site only)
- **inc/admin/newsletter-settings.php**: Admin settings page (newsletter site only)
- **templates/**: Template files for all newsletter forms and display

### Key Integration Patterns

#### Newsletter Site Homepage as Archive
On newsletter.extrachill.com, the homepage displays the newsletter archive:
- `template_include` filter redirects homepage to newsletter archive template
- Clean, dedicated landing page for all newsletters
- No separate `/newsletters/` archive URL needed
- Template hierarchy: theme's `archive-newsletter.php` > plugin template

#### Template Override System
The plugin uses WordPress template hierarchy override with `template_include` filter:
- Plugin templates in `/templates/` directory take precedence
- Theme templates serve as fallback
- Conditional template loading based on post type and site context

#### Hook-Based Theme Integration
The plugin integrates with themes via action hooks:
- `extrachill_navigation_before_social_links` - Navigation menu subscription form (network-wide)
- `extrachill_home_grid_bottom_right` - Latest newsletters grid (main blog only, not newsletter site)
- `extrachill_after_post_content` - Content subscription form (network-wide)
- `extrachill_above_footer` - Footer subscription form (network-wide)
- `extrachill_after_news_wire` - Festival wire tip form (network-wide)
- `extrachill_sidebar_bottom` - Recent newsletters sidebar widget (network-wide)
- `extrachill_archive_below_description` - Archive page subscription form (newsletter site)

#### Asset Loading Strategy
- **Forms CSS**: Loaded globally for navigation and other forms (`newsletter-forms.css`)
- **Sidebar CSS**: Loaded globally for sidebar widgets (`sidebar.css`)
- **Newsletter CSS**: Loaded conditionally on newsletter/festival pages (`newsletter.css`)
- **Global JavaScript**: Loaded site-wide with AJAX localization (`newsletter.js`)
- **Popup JavaScript**: Loaded conditionally for popup functionality (`newsletter-popup.js`)
- **File-based Versioning**: Uses `filemtime()` for cache busting on all assets

### Sendy Integration Architecture

#### API Configuration
- Settings stored in WordPress options (`extrachill_newsletter_settings`)
- Multiple list IDs for different subscription contexts (navigation, homepage, popup, archive, content, footer, festival_wire_tip)
- Centralized configuration via `get_sendy_config()` function
- Network-wide settings storage using `get_site_option()`

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
- Content subscription (`submit_newsletter_content_form`)
- Footer subscription (`submit_newsletter_footer_form`)
- Festival wire tip submission (`newsletter_festival_wire_tip_submission`)

All forms use the centralized `extrachill_multisite_subscribe()` function with context-based list routing.

### Custom Post Type Implementation
- **Post Type**: `newsletter` with full WordPress features (newsletter site only)
- **Archive Support**: Homepage serves as archive on newsletter.extrachill.com
- **Template System**: Plugin templates override theme templates with conditional loading
- **REST API**: Enabled for potential mobile/API integration
- **Admin Interface**: Custom meta boxes for Sendy campaign management (newsletter site only)
- **Network Visibility**: Post type registered only on newsletter site, not network-wide

### Security Implementation
- **Nonce Verification**: All AJAX requests use WordPress nonces with context-specific naming
- **Rate Limiting**: Festival wire tip submissions limited to prevent spam (5-minute cooldown)
- **Cloudflare Turnstile**: Anti-spam verification for tip submissions
- **Honeypot Protection**: Hidden form fields to detect automated submissions
- **Capability Checks**: Admin functionality requires proper permissions
- **Input Sanitization**: User input sanitized and validated before processing
- **Direct Access Prevention**: All files check for `ABSPATH` constant
- **Email Validation**: Comprehensive email format and domain validation

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
- Use proper nonce verification with context-specific nonces
- Implement rate limiting for public forms where appropriate
- Include Cloudflare Turnstile verification for spam-sensitive forms
- Follow existing response patterns (`wp_send_json_success`/`wp_send_json_error`)
- Provide user feedback through JavaScript responses
- Log subscription attempts for debugging and analytics

### Sendy Integration
When modifying Sendy integration:
- All API calls go through centralized `extrachill_multisite_subscribe()` function
- Use `get_sendy_config()` for configuration access
- Register new integration contexts via `newsletter_form_integrations` filter
- Handle API errors gracefully with user feedback
- Maintain subscription context separation (different list IDs)
- Test subscription flows for each integration context

## Deployment and Migration

### Initial Setup
1. Create newsletter.extrachill.com site in Network Admin → Sites
2. Network activate extrachill-newsletter plugin
3. Navigate to Tools → Admin Tools → Newsletter Migration
4. Run migration to move existing newsletters from main site

### Migration Tool
Located in extrachill-admin-tools plugin (`inc/tools/newsletter-migration.php`):
- Copies all newsletter posts from extrachill.com to newsletter.extrachill.com
- Preserves all post data, meta, and Sendy campaign IDs
- Optional deletion from main site after successful migration
- Prevents duplicates with date/title matching
- AJAX-powered with progress reporting

### Network Site Structure
- **newsletter.extrachill.com**: Newsletter management and archive homepage
- **All other sites**: Subscription forms via network-wide hooks
- **Main blog**: Latest newsletters grid (not shown on newsletter site)

## Build Process

The build process creates production-ready WordPress plugin packages:

1. **Version Extraction**: Automatically reads version from plugin header
2. **File Exclusion**: Uses `.buildignore` patterns to exclude development files
3. **Structure Validation**: Ensures all required plugin files are present
4. **Production Optimization**: Removes development files and creates clean structure
5. **ZIP Creation**: Generates non-versioned ZIP file for WordPress deployment

Essential files for plugin functionality:
- Main plugin file with proper WordPress headers
- `/inc/` directory with all PHP modules
- `/templates/` directory with template files
- `/assets/` directory with CSS/JS files
- `.buildignore` file for build exclusions

The build script is symlinked from the shared build system at `../../.github/build.sh` and supports:
- Auto-detection of plugin from `Plugin Name:` header
- Production dependency installation (`composer install --no-dev`)
- Rsync-based file copying with exclusion patterns
- Plugin structure validation
- Clean directory and ZIP creation

This architecture enables rapid development while maintaining WordPress best practices for security, performance, and extensibility.