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
- Supports multiple contexts: navigation, homepage, popup (backend only - no frontend display), archive, content, footer, festival_wire_tip

### Conditional Loading Pattern

The plugin uses `is_newsletter_site()` helper to conditionally load features:

**Newsletter Site Only** (newsletter.extrachill.com):
- Newsletter custom post type (`inc/core/newsletter-post-type.php`)
- Admin settings page (`inc/core/newsletter-settings.php`)
- Homepage as archive template override

**Network-Wide** (all sites):
- Asset management (`inc/core/assets.php`)
- Sendy API integration (`inc/core/sendy-api.php`)
- Email template generation (`inc/core/templates/email-template.php`)
- AJAX handlers (`inc/ajax/handlers.php`)
- Hook integrations (`inc/core/hooks/breadcrumbs.php`, `inc/core/hooks/forms.php`, `inc/core/hooks/homepage.php`, `inc/core/hooks/post-meta.php`, `inc/core/hooks/sidebar.php`)

### Modular Structure

The plugin follows a modular architecture with organized directory structure:

- **extrachill-newsletter.php**: Main plugin file with network activation, conditional loading, constants, integration registration, and inline form display functions
- **inc/core/assets.php**: Centralized asset management with conditional enqueuing and `filemtime()` cache busting (network-wide)
- **inc/core/sendy-api.php**: Sendy API integration, subscription functions, and configuration (network-wide)
- **inc/core/newsletter-post-type.php**: Custom post type registration (newsletter site only)
- **inc/core/newsletter-settings.php**: Admin settings page (newsletter site only)
- **inc/core/templates/email-template.php**: Email HTML generation from post content (network-wide)
- **inc/core/templates/homepage.php**: Homepage template override for newsletter archive (newsletter site only)
- **inc/core/templates/recent-newsletters.php**: Sidebar widget template (network-wide)
- **inc/core/templates/forms/**: Directory containing all subscription form templates
- **inc/core/hooks/breadcrumbs.php**: Breadcrumb customization (network-wide)
- **inc/core/hooks/forms.php**: Archive form display hooks (network-wide)
- **inc/core/hooks/homepage.php**: Homepage template override and query modification (network-wide)
- **inc/core/hooks/post-meta.php**: Post meta customization for newsletters (network-wide)
- **inc/core/hooks/sidebar.php**: Sidebar widget integration (network-wide)
- **inc/ajax/handlers.php**: All AJAX endpoints with security and rate limiting (network-wide)
- **assets/css/**: Stylesheet directory (newsletter.css, newsletter-forms.css, sidebar.css, admin.css)
- **assets/js/**: JavaScript directory (newsletter.js)

### Key Integration Patterns

#### Newsletter Site Homepage as Archive
On newsletter.extrachill.com, the homepage displays the newsletter archive:
- `extrachill_template_homepage` filter (provided by extrachill theme) redirects homepage to plugin's archive template
- Plugin template: `inc/core/templates/homepage.php`
- Clean, dedicated landing page for all newsletters with subscription form integration
- No separate `/newsletters/` archive URL needed
- Custom archive header and query modification via hooks in `inc/core/hooks/homepage.php`

#### Template Override System
The plugin uses theme filter integration for template overrides:
- Homepage template override via `extrachill_template_homepage` filter
- Form templates in `inc/core/templates/forms/` directory
- Theme templates can override plugin forms by placing templates in theme root
- Fallback rendering system for form integrations without custom templates

#### Hook-Based Theme Integration
The plugin integrates with themes via action hooks:
- `extrachill_navigation_before_social_links` - Navigation menu subscription form (network-wide)
- `extrachill_home_grid_bottom_right` - Latest newsletters grid (main blog only, not newsletter site)
- `extrachill_home_final_right` - Homepage subscription form (main blog only)
- `extrachill_after_post_content` - Content subscription form (network-wide)
- `extrachill_above_footer` - Footer subscription form (network-wide)
- `extrachill_after_news_wire` - Festival wire tip form (network-wide)
- `extrachill_sidebar_bottom` - Recent newsletters sidebar widget (network-wide)
- `extrachill_archive_below_description` - Archive page subscription form (archive pages)
- `newsletter_homepage_hero` - Newsletter homepage hero form (newsletter site homepage only)

#### Asset Loading Strategy
Centralized in `inc/core/assets.php` with on-demand loading:

**Registered Handles** (registered on `wp_enqueue_scripts`, enqueued only when components render):
- `extrachill-newsletter` - Main JavaScript with REST nonce localization
- `extrachill-newsletter-forms` - Forms CSS for subscription forms
- `extrachill-newsletter-sidebar` - Sidebar CSS for recent newsletters widget

**On-Demand Enqueuing**: Assets are registered globally but enqueued only when:
- **Sidebar template** (`recent-newsletters.php`) renders with posts
- **Form display functions** render subscription forms (navigation, content, footer, homepage, archive, festival wire tip)
- **Dynamic integration forms** render via `newsletter_render_integration_form()`

**Conditional Page CSS**:
- **Newsletter CSS**: Enqueued only on newsletter/festival wire archive and single pages (`assets/css/newsletter.css`)
- **Admin CSS**: Loaded only on newsletter post edit screens (`assets/css/admin.css`)

**File-based Versioning**: Uses `filemtime()` for cache busting on all assets

**Theme Integration**: Forces theme archive CSS on newsletter homepage when needed

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
- Popup subscription (`submit_newsletter_popup_form`) - backend infrastructure exists, no frontend display currently implemented
- Content subscription (`submit_newsletter_content_form`)
- Footer subscription (`submit_newsletter_footer_form`)
- Festival wire tip submission (`newsletter_festival_wire_tip_submission`)

All forms use the centralized `extrachill_multisite_subscribe()` function with context-based list routing.

**Note**: The popup integration context is registered with backend AJAX handler and nonce generation, but the frontend JavaScript module for displaying the popup was removed during refactoring. The infrastructure remains ready for future popup implementation.

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
- Register handlers in `inc/ajax/handlers.php`
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
- `/inc/core/` directory with core PHP modules
- `/inc/ajax/` directory with AJAX handlers
- `/inc/core/hooks/` directory with WordPress hook integrations
- `/inc/core/templates/` directory with template files
- `/inc/core/templates/forms/` directory with form templates
- `/assets/css/` directory with stylesheets
- `/assets/js/` directory with JavaScript files
- `.buildignore` file for build exclusions

The build script is symlinked from the shared build system at `../../.github/build.sh` and supports:
- Auto-detection of plugin from `Plugin Name:` header
- Production dependency installation (`composer install --no-dev`)
- Rsync-based file copying with exclusion patterns
- Plugin structure validation
- Clean directory and ZIP creation

This architecture enables rapid development while maintaining WordPress best practices for security, performance, and extensibility.