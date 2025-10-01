# ExtraChill Newsletter Plugin

A comprehensive WordPress plugin for newsletter management and Sendy integration, extracted from the ExtraChill theme for improved modularity and maintainability.

## Features

- **Custom Newsletter Post Type**: Complete newsletter management with archive and single page templates
- **Sendy Integration**: Full API integration for campaign creation, updates, and subscription management
- **Multiple Subscription Forms**: Archive page, homepage, navigation menu, and popup forms
- **Template Override System**: Plugin-provided templates that override theme templates
- **AJAX-Powered Forms**: Seamless subscription experience without page reloads
- **Shortcode Support**: Flexible newsletter widgets and forms for any content area
- **Responsive Design**: Mobile-optimized forms and layouts
- **Admin Integration**: Campaign management meta box with push-to-Sendy functionality

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Active Sendy installation for email functionality
- Write permissions for plugin directory

## Installation

### Via WordPress Admin (Recommended)
1. Download the latest release ZIP from the plugin repository
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Choose the ZIP file and click "Install Now"
4. Activate the plugin

### Manual Installation
1. Extract the plugin files to `wp-content/plugins/extrachill-newsletter/`
2. Go to WordPress Admin → Plugins and activate "ExtraChill Newsletter"

### Development Installation
1. Navigate to plugin directory and create production build:
   ```bash
   cd extrachill-plugins/extrachill-newsletter
   ./build.sh
   ```
2. Install the generated ZIP file from `/build` directory

## Configuration

### Sendy API Settings

The plugin uses hardcoded Sendy configuration for security. To modify settings, edit the `get_sendy_config()` function in `includes/newsletter-sendy-integration.php`:

```php
function get_sendy_config() {
    return array(
        'api_key' => 'your-sendy-api-key',
        'sendy_url' => 'https://your-sendy-installation.com/sendy',
        'from_name' => 'Your Newsletter Name',
        'from_email' => 'newsletter@yourdomain.com',
        'reply_to' => 'reply@yourdomain.com',
        'brand_id' => '1',
        'list_ids' => array(
            'main' => 'your-main-list-id',
            'archive' => 'your-archive-list-id',
            'popup' => 'your-popup-list-id',
            'homepage' => 'your-homepage-list-id',
        ),
    );
}
```

### Theme Integration

The plugin includes a homepage section that can be displayed by adding this to your theme:

```php
do_action('extrachill_newsletter_homepage_section');
```

## Usage

### Creating Newsletters

1. Go to WordPress Admin → Newsletters → Add New
2. Write your newsletter content using the WordPress editor
3. Add a featured image if desired
4. Publish the newsletter
5. Use the "Push to Sendy" button in the Sendy Integration meta box to send the campaign

### Subscription Forms

The plugin provides multiple subscription forms:

- **Archive Page**: Automatically displays on the newsletter archive page
- **Homepage**: Use the action hook or template inclusion
- **Navigation Menu**: Automatically integrates with theme navigation (filter-based)
- **Popup**: Automatically loads on appropriate pages
- **Shortcodes**: Manual placement using shortcodes

### Available Shortcodes

#### Recent Newsletters Widget
```php
[recent_newsletters count="5" show_dates="true" show_view_all="true" title="Latest Updates"]
```

#### Subscription Form
```php
[newsletter_subscription_form list="archive" title="Subscribe" button_text="Join Us"]
```

#### Archive Link
```php
[newsletter_archive_link text="Browse Past Issues"]
```

#### Newsletter Count
```php
[newsletter_count format="text" text_format="We've published %d newsletters!"]
```

### Template Customization

The plugin uses its own templates but allows theme overrides. To customize templates:

1. Copy templates from `plugins/extrachill-newsletter/templates/`
2. Place them in your theme's root directory
3. Modify as needed (plugin templates will be used as fallback)

## AJAX Endpoints

The plugin registers these AJAX endpoints:

- `submit_newsletter_form` - Archive page subscription
- `submit_newsletter_popup_form` - Popup subscription
- `subscribe_to_sendy_home` - Homepage subscription
- `subscribe_to_sendy` - Navigation subscription
- `submit_newsletter_shortcode_form` - Shortcode form subscription
- `push_newsletter_to_sendy_ajax` - Admin campaign management

## Hooks and Filters

### Actions
- `extrachill_newsletter_homepage_section` - Display homepage newsletter section
- `extrachill_after_newsletter_content` - After newsletter content display
- `newsletter_subscription_logged` - After subscription attempt logging

### Filters
- `wp_nav_menu_items` - Adds newsletter form to navigation menu
- `template_include` - Template override system

## Development

### Building the Plugin

```bash
# Create production ZIP
./build.sh

# Output: /build/extrachill-newsletter/ directory and /build/extrachill-newsletter.zip file
```

### File Structure

```
extrachill-newsletter/
├── extrachill-newsletter.php          # Main plugin file
├── includes/
│   ├── newsletter-post-type.php       # Custom post type registration
│   ├── newsletter-sendy-integration.php # Sendy API integration
│   ├── newsletter-ajax-handlers.php   # AJAX request handlers
│   └── newsletter-shortcodes.php      # Shortcode functionality
├── templates/
│   ├── archive-newsletter.php         # Newsletter archive template
│   ├── single-newsletter.php          # Single newsletter template
│   ├── content-newsletter.php         # Newsletter content template
│   └── homepage-section.php           # Homepage section template
├── assets/
│   ├── newsletter.css                  # Consolidated styles
│   └── newsletter.js                   # Consolidated JavaScript
├── build.sh                           # Production build script
└── README.md                          # This file
```

### Architecture

The plugin follows WordPress best practices:

- **Modular Structure**: Functionality split into focused include files
- **Template Override System**: Plugin templates with theme override capability
- **Conditional Asset Loading**: CSS/JS only loads when needed
- **Security First**: Nonce verification, capability checks, input sanitization
- **Translation Ready**: All strings properly internationalized
- **Hook-Based Integration**: Extensible via WordPress actions and filters

## Changelog

### Version 1.0.0
- Initial release
- Extracted newsletter functionality from ExtraChill theme
- Complete Sendy integration
- Multiple subscription forms
- Template override system
- Admin campaign management
- Shortcode support
- Responsive design

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