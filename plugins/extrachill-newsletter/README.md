# ExtraChill Newsletter Plugin

A comprehensive WordPress plugin for newsletter management and Sendy integration, extracted from the ExtraChill theme for improved modularity and maintainability.

## Features

- **Custom Newsletter Post Type**: Complete newsletter management with archive and single page templates
- **Sendy Integration**: Full API integration for campaign creation, updates, and subscription management
- **Decoupled Subscription Forms**: Generic form template with context-based presets
- **Template Override System**: Plugin-provided templates that override theme templates
- **REST API-Powered Forms**: Seamless subscription experience without page reloads
- **Integration System**: Declarative form registration via WordPress filters
- **Responsive Design**: Mobile-optimized forms and layouts
- **Admin Integration**: Campaign management meta box with push-to-Sendy functionality

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Active Sendy installation for email functionality
- WordPress multisite (network-activated)

## Build + deployment

Build the production ZIP with `./build.sh` (symlinked to `/.github/build.sh`).

Deployments and remote operations run through **Homeboy** (`homeboy/` in this repo).

The build artifact is `build/extrachill-newsletter.zip`.


## Configuration

### Sendy API Settings

The plugin provides a comprehensive admin settings interface for configuration. Navigate to **Newsletter → Settings** on newsletter.extrachill.com to configure:

- **Global Sendy Settings**: API key, Sendy URL, from email, reply-to email, brand ID
- **List ID Configuration**: Sendy list IDs for each integration context

All settings are stored network-wide in `get_site_option('extrachill_newsletter_settings')` and accessible from all sites in the multisite network.

**Zero Hardcoded Credentials**: All API keys and list IDs are configurable through the admin UI.

### Integration Contexts

The plugin supports multiple subscription contexts through a declarative registration system:

- **Navigation**: Navigation menu subscription form (network-wide, handled by newsletter plugin)
- **Homepage**: Main homepage subscription form (called by blog plugin)
- **Archive**: Newsletter archive page subscription (newsletter site only)
- **Content**: Newsletter form after post content (called by theme)
- **Contact**: Subscription checkbox in contact forms (called by contact plugin)

For detailed information on adding new contexts, see [docs/integrations.md](docs/integrations.md).

### Theme Integration

The plugin provides a decoupled action hook for rendering newsletter forms:

```php
// Render newsletter form for a specific context
do_action( 'extrachill_render_newsletter_form', 'homepage' );
do_action( 'extrachill_render_newsletter_form', 'content' );
```

Context presets are defined in `extrachill_get_newsletter_context_presets()` and can be customized via the `extrachill_newsletter_form_args` filter.

## Usage

### Creating Newsletters

1. Go to WordPress Admin → Newsletters → Add New
2. Write your newsletter content using the WordPress editor
3. Add a featured image if desired
4. Publish the newsletter
5. Use the "Push to Sendy" button in the Sendy Integration meta box to send the campaign (calls REST API endpoint)

### Subscription Forms

The plugin uses a decoupled architecture where forms are rendered via the `extrachill_render_newsletter_form` action:

- **Navigation**: Handled by newsletter plugin (network-wide on every page)
- **Archive**: Handled by newsletter plugin (newsletter site archive pages)
- **Homepage**: Called by blog plugin via `do_action('extrachill_render_newsletter_form', 'homepage')`
- **Content**: Called by theme via `do_action('extrachill_render_newsletter_form', 'content')`

All forms use the generic template (`generic-form.php`) with context-specific presets for heading, description, layout, and styling. Forms are submitted via the REST API for a seamless user experience.

### Template Customization

The plugin uses a single generic form template (`generic-form.php`) with context-based presets. To customize form appearance:

1. Use the `extrachill_newsletter_form_args` filter to modify preset values
2. Override CSS styles in your theme targeting `.newsletter-form-wrapper` classes

## REST API Documentation

The plugin provides subscription and campaign functionality via REST API (endpoints registered in `extrachill-api` plugin). Detailed documentation is available in [docs/api-reference.md](docs/api-reference.md).

- `POST /wp-json/extrachill/v1/newsletter/subscribe` - Public newsletter subscription
- `POST /wp-json/extrachill/v1/newsletter/campaign/push` - Admin campaign push to Sendy

## Hooks and Filters

### Actions
- `extrachill_render_newsletter_form` - Main action to render newsletter form (accepts context parameter)
- `extrachill_navigation_before_social_links` - Navigation menu subscription form (network-wide)
- `extrachill_home_grid_bottom_right` - Latest newsletters grid display (main blog only)
- `extrachill_sidebar_bottom` - Recent newsletters sidebar widget (network-wide)
- `extrachill_archive_below_description` - Archive page subscription form (archive pages)
- `newsletter_homepage_hero` - Newsletter homepage hero form (newsletter site homepage only)

### Filters
- `newsletter_form_integrations` - Register newsletter integration contexts
- `extrachill_newsletter_form_args` - Customize form arguments per context
- `extrachill_homepage_content` - Newsletter homepage content integration (newsletter site)
- `extrachill_post_meta` - Customize post meta display for newsletters
- `extrachill_breadcrumbs_override_trail` - Customize breadcrumb trail for newsletters

## Development

### Building the Plugin

```bash
# Create production ZIP
./build.sh

# Output: Only /build/extrachill-newsletter.zip file
```

### File Structure

```
extrachill-newsletter/
├── extrachill-newsletter.php          # Main plugin file
├── inc/
│   └── core/
│       ├── assets.php                 # Centralized asset management
│       ├── sendy-api.php              # Sendy API integration
│       ├── newsletter-post-type.php   # Custom post type registration
│       ├── newsletter-settings.php    # Admin settings page
│       ├── hooks/
│       │   ├── breadcrumbs.php        # Breadcrumb customization
│       │   ├── forms.php              # Archive form hooks
│       │   ├── homepage.php           # Homepage override hooks
│       │   ├── post-meta.php          # Post meta customization
│       │   └── sidebar.php            # Sidebar widget hooks
│       └── templates/
│           ├── homepage.php           # Homepage template override
│           ├── email-template.php     # Email HTML generation
│           ├── recent-newsletters.php # Sidebar widget template
│           └── forms/
│               └── generic-form.php   # Generic form template (all contexts)
├── assets/
│   ├── css/
│   │   ├── newsletter.css             # Newsletter page styles
│   │   ├── newsletter-forms.css       # Form-specific styles
│   │   ├── sidebar.css                # Sidebar widget styles
│   │   └── admin.css                  # Admin interface styles
│   └── js/
│       └── newsletter.js              # JavaScript functionality
├── build.sh                           # Production build script (symlink)
├── .buildignore                       # Build exclusion patterns
└── README.md                          # This file
```

### Architecture

The plugin follows WordPress best practices:

- **Organized Directory Structure**: Functionality organized into `/core/`, `/hooks/`, and `/templates/` directories
- **Modular Structure**: Functionality split into focused include files with single responsibility
- **Centralized Asset Management**: All asset loading handled in `inc/core/assets.php`
- **Template Override System**: Plugin templates with theme override capability
- **Conditional Asset Loading**: CSS/JS only loads when needed with `filemtime()` cache busting
- **Security First**: Nonce verification, capability checks, input sanitization, rate limiting
- **Translation Ready**: All strings properly internationalized
- **Hook-Based Integration**: Extensible via WordPress actions and filters

## Changelog

See [docs/CHANGELOG.md](docs/CHANGELOG.md) for full version history.

## Support

For support and development questions:

- Review the code comments for technical details
- Check WordPress error logs for debugging information
- Verify Sendy API configuration and connectivity
- Ensure proper WordPress permissions and capabilities

## License

This plugin is developed for the ExtraChill platform. All rights reserved.

---

**Developed by Chris Huber for ExtraChill**
Website: https://extrachill.com
GitHub: https://github.com/chubes4