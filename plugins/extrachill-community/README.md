# Extra Chill Community Theme

A WordPress theme for the ExtraChill community platform providing forum enhancements, cross-domain authentication, and bbPress integration. Focuses exclusively on community and forum features. Artist platform functionality has been fully migrated to a separate plugin.

## Overview

**Extra Chill Community** is a WordPress theme serving the community platform at `community.extrachill.com`:
- `community.extrachill.com` - Main community platform (WordPress/bbPress) **[This theme]**
- `extrachill.com` - Main website **[Cross-domain integration only]**
- Artist platform features **[Fully migrated to separate plugin]**

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
├── page-templates/            # Custom page templates  
├── bbpress/                   # bbPress template overrides
├── extrachill-integration/    # Cross-domain authentication
├── forum-features/            # Community forum enhancements
├── login/                     # Custom authentication system
├── css/                       # Modular stylesheets
├── js/                        # JavaScript components (19 files total)
├── fonts/                     # Custom font files
└── vendor/                    # Composer dependencies
```

## Core Features

### 1. Forum Features System

**Organized Feature Architecture** in `forum-features/` directory:
```php
// Master loader
require_once get_stylesheet_directory() . '/forum-features/forum-features.php';

// Admin features: moderation, notifications, forum management, restricted forums
// Content features: embeds, editor customization, breadcrumbs, queries, pagination
// Social features: following, upvoting, notifications, mentions, rank system with points
// User features: custom avatars, profile management, verification, settings, online tracking
```

**bbPress Integration**:
```php
// Custom templates override default bbPress styling
if (bbp_is_forum_archive() || is_front_page()) {
    wp_enqueue_style('forums-loop', get_stylesheet_directory_uri() . '/css/forums-loop.css');
}
```

### 2. Cross-Domain Authentication

**Session Token System**:
```php
// Automatic login across domains
extrachill_login_user_across_domains($user_id);

// Validates via custom table: user_session_tokens (with wp_ prefix)
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

### 3. User Management & Notifications

**User Profile System**:
```php
// User can add multiple social/music platform links
$existing_links = get_user_meta($user_id, '_user_profile_dynamic_links', true);

// Supported link types: website, instagram, twitter, facebook, spotify, soundcloud, bandcamp
// Custom avatar upload system with AJAX
```

**Notification System**:
```php
// Header notification bell with unread count
$notifications = get_user_meta($current_user_id, 'extrachill_notifications', true);
$unread_count = count(array_filter($notifications, function($n) { return !$n['read']; }));

// User avatar dropdown menu with artist platform integration
// Conditional links to artist profile management (via plugin)
```

**Email Management**:
```php
// Email change verification system
extrachill_send_email_change_verification($user_id, $new_email, $hash);
extrachill_send_email_change_confirmation($user_id, $old_email, $new_email);
```

### 4. Cross-Domain Integration

**External Platform Features**:
- Cross-domain authentication with extrachill.com
- Session token validation and management
- Seamless commenting system integration
- REST API endpoints for external access

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

**JavaScript Architecture** (19 specialized files total):
```php
// Core utilities (js/ directory - 13 files)
wp_enqueue_script('extrachill-utilities', get_stylesheet_directory_uri() . '/js/utilities.js', ['jquery']);

// Forum features (forum-features/ directory - 4 files)
wp_enqueue_script('extrachill-follow', get_stylesheet_directory_uri() . '/forum-features/social/js/extrachill-follow.js', ['jquery']);
wp_enqueue_script('upvote', get_stylesheet_directory_uri() . '/forum-features/social/js/upvote.js', ['jquery']);
wp_enqueue_script('extrachill-mentions', get_stylesheet_directory_uri() . '/forum-features/social/js/extrachill-mentions.js', ['jquery']);
wp_enqueue_script('extrachill-admin', get_stylesheet_directory_uri() . '/forum-features/social/rank-system/js/extrachill_admin.js', ['jquery']);

// Login system (login/ directory - 2 files)  
wp_enqueue_script('join-flow', get_stylesheet_directory_uri() . '/login/js/join-flow.js', ['jquery']);
wp_enqueue_script('seamless-login', get_stylesheet_directory_uri() . '/login/js/seamless-login.js', ['jquery']);
```

### Database Schema

**Custom Tables**:
```sql
-- Cross-domain authentication
user_session_tokens (user_id, token, expiration) -- with wp_ prefix
```

**Meta Fields**:
```php
// Theme meta fields
get_post_meta($forum_id, '_show_on_homepage'); // Boolean for homepage display
get_user_meta($user_id, '_user_profile_dynamic_links'); // User social links
get_user_meta($user_id, 'ec_custom_title'); // Custom user titles
get_user_meta($user_id, 'extrachill_notifications'); // User notification data
get_user_meta($user_id, '_artist_profile_ids'); // Artist platform plugin integration
get_user_meta($user_id, 'user_is_artist'); // Artist account flag
get_user_meta($user_id, 'user_is_professional'); // Professional account flag
```

### Template System

**Page Templates**:
```php
// Template Name: Login/Register Page Template
get_header();
// Custom login/register interface with join flow modal

// Template Name: Account Settings  
// User settings management with form processing and email change verification

// Template Name: Notifications Feed
// User notification system with unread status management
```

**bbPress Overrides**:
```php
// Custom templates in bbpress/ directory
// - loop-single-forum.php
// - content-single-topic.php  
// - user-profile.php (enhanced with social links)
// - form-user-edit.php (enhanced profile editing)
// - loop-forums.php (custom forum loop styling)
```

## Configuration

### Filter System

The theme provides a filter system for plugins to extend functionality without modifying theme files.

#### Avatar Menu Filter

The `ec_avatar_menu_items` filter allows plugins to add custom menu items to the user avatar dropdown menu:

```php
add_filter( 'ec_avatar_menu_items', 'my_plugin_avatar_menu_items', 10, 2 );

function my_plugin_avatar_menu_items( $menu_items, $user_id ) {
    // Add artist profile management for users with artist accounts
    $user_artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
    
    if ( ! empty( $user_artist_ids ) ) {
        $menu_items[] = array(
            'url'      => home_url( '/manage-artist-profiles/' ),
            'label'    => __( 'Manage Artist Profile(s)', 'textdomain' ),
            'priority' => 5  // Appears before settings
        );
        
        $menu_items[] = array(
            'url'      => home_url( '/manage-link-page/' ),
            'label'    => __( 'Manage Link Page(s)', 'textdomain' ),
            'priority' => 6
        );
    } else {
        // Show create option for artists/professionals
        $is_artist = get_user_meta( $user_id, 'user_is_artist', true );
        if ( $is_artist === '1' ) {
            $menu_items[] = array(
                'url'      => home_url( '/manage-artist-profiles/' ),
                'label'    => __( 'Create Artist Profile', 'textdomain' ),
                'priority' => 5
            );
        }
    }
    
    return $menu_items;
}
```

**Menu Item Structure:**
- `url` (string, required) - The menu item URL  
- `label` (string, required) - The menu item display text
- `priority` (int, optional) - Sort order (default: 10, lower = higher in menu)

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
// Theme AJAX handlers
wp_ajax_follow_user                    // User following system
wp_ajax_upvote_content                 // Content upvoting
wp_ajax_custom_avatar_upload           // Custom avatar uploads
wp_ajax_clear_most_active_users_cache  // Cache management
wp_ajax_user_mention_autocomplete      // User mention system
wp_ajax_save_user_profile_links        // Dynamic social links
```

## Testing

```bash
# Testing Areas:
# 1. Forum Features: Test all forum enhancement features across 4 categories
# 2. Cross-Domain Authentication: Session tokens, auto-login, cookie validation
# 3. bbPress Integration: Custom templates, stylesheet conflicts, functionality
# 4. JavaScript Components: All 19 JS files loading and functioning correctly
# 5. User Management: Profiles, avatars, settings, verification, notifications
# 6. Authentication System: Login/register, email verification, session handling
# 7. Email Systems: Registration emails, email change verification flow
```

## Deployment

**Production Setup**:
1. Install theme on community.extrachill.com WordPress
2. Activate bbPress plugin (required)
3. Configure cross-domain cookies (`.extrachill.com`)
4. Run `composer install` for PHP dependencies

**Domain Configuration**:
```php
// wp-config.php additions for cross-domain
define('COOKIE_DOMAIN', '.extrachill.com');
define('EXTRACHILL_API_URL', 'https://community.extrachill.com');
```

## Architecture Notes

- **Community-Focused**: Streamlined theme focused exclusively on community and forum functionality
- **Plugin Integration**: Works seamlessly with `extrachill-artist-platform` plugin (all artist features migrated to plugin)
- **No Build System**: Direct file inclusion, no compilation required
- **PSR-4 Ready**: Composer autoloader configured (`Chubes\Extrachill\` namespace) 
- **Organized Structure**: Forum features in structured subdirectories with master loader
- **WordPress Native**: Full compliance with WordPress coding standards
- **Performance Focused**: Conditional asset loading, dynamic versioning, modular CSS
- **Cross-Domain Ready**: Session token system for seamless authentication across domains

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## Author

**Chris Huber** - https://chubes.net