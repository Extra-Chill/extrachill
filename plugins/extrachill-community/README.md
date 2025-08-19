# Extra Chill Community Theme

A comprehensive WordPress theme powering the ExtraChill community platform - band platforms, link page management, and cross-domain authentication for musicians.

## Overview

**Extra Chill Community** is a standalone WordPress theme serving the community platform at `community.extrachill.com` with integration capabilities for:
- `community.extrachill.com` - Main platform (WordPress/bbPress) **[This theme]**
- `extrachill.link` - Public link pages ("link in bio" service) **[Served by this theme]**
- `extrch.co` - Short domain variant **[Served by this theme]**
- `extrachill.com` - Main website **[External integration only]**

## Quick Start

### Installation

```bash
# Navigate to theme directory
cd wp-content/themes/extrachill-community

# Install PHP dependencies
composer install

# No build process required - uses direct file inclusion
```

### Theme Structure

```
extrachill-community/
├── functions.php              # Theme setup and WordPress features
├── style.css                  # Main stylesheet with theme header
├── index.php                  # Required WordPress template fallback
├── band-platform/             # Core band and link page features
├── page-templates/            # Custom page templates
├── bbpress/                   # bbPress template overrides
├── extrachill-integration/    # Cross-domain authentication
├── forum-features/            # Community forum enhancements
├── css/                       # Modular stylesheets
├── js/                        # JavaScript components
└── vendor/                    # Composer dependencies
```

## Core Features

### 1. Band Platform

**Custom Post Type**: `band_profile`
```php
// Create a band profile
$band_id = wp_insert_post([
    'post_type' => 'band_profile',
    'post_title' => 'Band Name',
    'post_status' => 'publish'
]);

// Associate with user
update_user_meta($user_id, '_band_profile_ids', [$band_id]);
```

**Automatic Forum Creation**: Each band gets a private bbPress forum
```php
// Forums are created automatically via band-platform/band-forums.php
// Access controlled by band membership
```

### 2. Link Page System

**Custom Post Type**: `band_link_page`
```php
// Get link page data (canonical provider)
$data = LinkPageDataProvider::get_data($link_page_id, $band_id, $overrides);

// Structure includes:
// - profile_img_url
// - band_name, description
// - social_links array
// - custom_css_vars for styling
```

**Public Access**: `extrachill.link/bandname` or `extrch.co/bandname`

### 3. Cross-Domain Authentication

**Session Token System**:
```php
// Automatic login across domains
extrachill_login_user_across_domains($user_id);

// Validates via custom table: wp_user_session_tokens
// Cookie domain: .extrachill.com (covers all subdomains)
```

**API Integration**:
```javascript
// Cross-domain comments for extrachill.com
seamlessComments.submitComment(commentData);

// Session validation
fetch('/wp-json/extrachill/v1/validate-session', {
    headers: { 'Authorization': 'Bearer ' + sessionToken }
});
```

### 4. Analytics & Tracking

**Link Click Tracking**:
```php
// Database: wp_link_page_analytics
record_link_click($link_page_id, $link_url, $visitor_data);

// View analytics in management interface
$analytics = get_link_page_analytics($link_page_id);
```

## Development

### Asset Management

**CSS Loading**:
```php
// Modular CSS with conditional loading
function modular_bbpress_styles() {
    if (is_bbpress()) {
        wp_enqueue_style('forums-loop', get_template_directory_uri() . '/css/forums-loop.css');
    }
}
```

**JavaScript Architecture**:
```php
// Specialized scripts with jQuery dependencies
wp_enqueue_script('extrachill-utilities', get_template_directory_uri() . '/js/utilities.js', ['jquery']);
wp_enqueue_script('manage-link-page', $js_path . '/manage-link-page.js', ['jquery']);
```

### Database Schema

**Custom Tables**:
```sql
-- Cross-domain authentication
wp_user_session_tokens (user_id, token, expiry)

-- Email subscribers with consent
wp_band_subscribers (band_id, email, consent_date)

-- Link analytics
wp_link_page_analytics (link_page_id, link_url, click_count, visitor_data)
```

**Meta Fields**:
```php
// Band associations
get_user_meta($user_id, '_band_profile_ids');

// Link page customization  
get_post_meta($link_page_id, '_link_page_custom_css_vars');

// Forum sections
get_post_meta($forum_id, '_bbp_forum_section'); // top/middle/bottom
```

### Template System

**Page Templates**:
```php
// Template Name: Login/Register Page Template
get_header();
// Custom login/register interface with join flow modal

// Template Name: Account Settings  
// User settings management with form processing
```

**bbPress Overrides**:
```php
// Custom templates in bbpress/ directory
// - loop-single-forum.php
// - content-single-topic.php  
// - user-profile.php (enhanced with band links)
```

## Configuration

### Theme Setup

```php
function extra_chill_community_setup() {
    // WordPress features
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form']);
    
    // Navigation menus (7 total)
    register_nav_menus([
        'primary' => 'Primary Menu',
        'footer' => 'Footer Menu',
        'footer-extra' => 'Footer Extra Menu'
        // + 4 additional footer areas
    ]);
}
```

### Performance Optimization

**Font Loading**:
```css
@font-face {
    font-family: 'WilcoLoftSans';
    src: url('fonts/WilcoLoftSans/WilcoLoftSans-Treble.woff2') format('woff2');
    font-display: swap;
}
```

**bbPress Optimization**:
```php
// Dequeue default bbPress styles to prevent conflicts
function extrachill_dequeue_bbpress_default_styles() {
    wp_dequeue_style('bbp-default');
}
add_action('wp_enqueue_scripts', 'extrachill_dequeue_bbpress_default_styles', 15);
```

## API Endpoints

### Custom REST API

```php
// Session validation
GET /wp-json/extrachill/v1/validate-session
Authorization: Bearer {token}

// User details
GET /wp-json/extrachill/v1/user-details/{user_id}

// Forums feed  
GET /wp-json/extrachill/v1/forums-feed
```

### AJAX Handlers

```javascript
// Link page updates
wp_ajax_save_link_page_data
wp_ajax_nopriv_record_link_click

// Band management
wp_ajax_add_band_member
wp_ajax_remove_band_member

// Social features
wp_ajax_follow_user
wp_ajax_upvote_content
```

## Testing

```bash
# Manual Testing Checklist:
# 1. Theme functionality on community.extrachill.com
# 2. Cross-domain authentication with extrachill.com  
# 3. Link page rendering on extrachill.link/extrch.co
# 4. bbPress forum integration
# 5. Band platform features and forum creation
# 6. Link page live preview and customization
# 7. Analytics tracking and QR code generation
```

## Deployment

**Production Setup**:
1. Install theme on community.extrachill.com WordPress
2. Activate bbPress plugin
3. Configure cross-domain cookies (`.extrachill.com`)
4. Set up URL rewrites for link pages
5. Configure QR code generation (requires GD extension)

**Domain Configuration**:
```php
// wp-config.php additions for cross-domain
define('COOKIE_DOMAIN', '.extrachill.com');
define('EXTRACHILL_API_URL', 'https://community.extrachill.com');
```

## Architecture Notes

- **No Build System**: Direct file inclusion, no webpack/compilation
- **PSR-4 Ready**: Composer autoloader configured (requires implementing `src/` structure)  
- **Modular Design**: Features isolated in subdirectories with 45+ JavaScript files
- **WordPress Native**: Uses core WordPress patterns and conventions
- **Performance Focused**: Conditional asset loading, optimized queries, and font inheritance system
- **Text Domain Complete**: All legacy `generatepress_child` references successfully migrated to `extra-chill-community`

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## Author

**Chris Huber** - https://chubes.net