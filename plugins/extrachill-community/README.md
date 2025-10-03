# Extra Chill Community Plugin

A WordPress plugin for the Extra Chill community platform providing forum enhancements, cross-domain authentication, and bbPress integration. Works with the extrachill theme to provide community functionality for community.extrachill.com.

**Version**: 1.0.0

## Overview

**Extra Chill Community** is a WordPress plugin providing community functionality:
- `community.extrachill.com` - Main community platform (WordPress/bbPress) **[Uses extrachill theme + this plugin]**
- `extrachill.com` - Main website **[Uses extrachill theme + cross-domain integration]**

## Quick Start

### Installation

```bash
# Navigate to plugin directory
cd wp-content/plugins/extrachill-community

# Install PHP dependencies (minimal composer.json)
composer install

# Activate plugin in WordPress admin
# Plugin integrates with extrachill theme
```

### Plugin Structure

```
extrachill-community/
├── extrachill-community.php      # Main plugin file
├── inc/                          # Core plugin functionality
│   ├── core/                     # Assets, bbPress templates, navigation, spam adjustments
│   ├── content/                  # Editor, content filters, recent feed
│   ├── social/                   # Upvoting, mentions, badges
│   │   ├── notifications/        # Notification system (6 files)
│   │   └── rank-system/          # Point calculation, forum rank
│   ├── user-profiles/            # Avatars, profiles, verification, user avatar menu
│   │   └── settings/             # Settings content and form handler
│   ├── home/                     # Homepage components (6 files)
│   └── assets/                   # CSS and JS files
│       ├── css/                  # 9 CSS files
│       └── js/                   # 7 JavaScript files
├── page-templates/               # Custom page templates (3 templates)
├── bbpress/                      # bbPress template overrides
└── vendor/                       # Composer dependencies
```

## Core Features

### 1. Forum Features System

**Explicit Loading Pattern** - All functionality loaded in `extrachill_community_init()`:
```php
// Main plugin file uses 37 direct require_once statements (NO master loader file)
// Load order: core (4) → content (4) → social (12) → user-profiles (10) → home (3)

// Core features (4 files): assets, bbPress templates, navigation, spam adjustments
// Content features (4 files): TinyMCE editor (2), content filters, recent feed
// Social features (12 files): upvoting, mentions, badges, rank system (2), notifications (7)
// User profile features (10 files): avatars, menu, profiles, verification, settings (2), online tracking, email changes, edit (2)
// Home features (3 files): latest post, actions, forum display

// Template components (3 files) loaded separately via include/filters:
// - inc/home/forum-home-header.php, forum-homepage.php, recently-active.php
```

**bbPress Integration**:
```php
// Plugin enhances bbPress functionality with conditional loading
if (bbp_is_forum_archive() || is_front_page() || bbp_is_single_forum()) {
    wp_enqueue_style('community-home',
        EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/home.css',
        ['extra-chill-community-style'],
        filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/home.css')
    );
}
```

### 2. Cross-Domain Authentication

**WordPress Multisite Native Authentication**:
```php
// WordPress multisite provides native cross-domain authentication
// No custom session tokens needed - WordPress handles this automatically
if (is_user_logged_in()) {
    // User authenticated across all .extrachill.com subdomains automatically
}
```

**Migration Complete**: The plugin now uses WordPress multisite native authentication exclusively. All custom session token functionality has been removed.

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

// User avatar dropdown menu with plugin integration support
// Extensible via ec_avatar_menu_items filter
```

### 4. Cross-Domain Integration

**WordPress Multisite Native**:
- Native WordPress multisite authentication across all Extra Chill domains
- Automatic cross-domain user sessions (no custom tokens)
- Performance optimization through native WordPress functions

## Development

### Asset Management

**CSS Loading** (9 files in inc/assets/css/):
```php
// Modular CSS with conditional loading
function modular_bbpress_styles() {
    if (bbp_is_forum_archive() || is_front_page() || bbp_is_single_forum()) {
        wp_enqueue_style('community-home',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/home.css',
            ['extra-chill-community-style'],
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/home.css')
        );
    }
}

// All CSS files: bbpress.css, home.css, leaderboard.css, notifications.css,
// replies-loop.css, settings-page.css, tinymce-editor.css, topics-loop.css, user-profile.css
```

**JavaScript Architecture** (7 files in inc/assets/js/):
```php
// Loaded via assets.php (5 files):
// - upvote.js (global), extrachill-mentions.js (bbPress), home-collapse.js (conditional)
// - utilities.js (global), tinymce-image-upload.js (bbPress)

// Loaded independently by feature modules (2 files):
// - custom-avatar.js (by inc/user-profiles/edit/upload-custom-avatar.php)
// - manage-user-profile-links.js (by inc/user-profiles/edit/user-links.php)

wp_enqueue_script('extrachill-utilities',
    EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/utilities.js',
    ['jquery'],
    filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/js/utilities.js'),
    true
);
```

### Database Schema

**Meta Fields**:
```php
// Theme meta fields
get_post_meta($forum_id, '_show_on_homepage'); // Boolean for homepage display
get_user_meta($user_id, '_user_profile_dynamic_links'); // User social links
get_user_meta($user_id, 'ec_custom_title'); // Custom user titles
get_user_meta($user_id, 'extrachill_notifications'); // User notification data
get_user_meta($user_id, 'user_is_artist'); // Artist account flag
get_user_meta($user_id, 'user_is_professional'); // Professional account flag
```

### Template System

**Page Templates** (3 templates in page-templates/):
```php
// Template Name: Leaderboard
// page-templates/leaderboard-template.php - Community leaderboard with user rankings

// Template Name: Recent Feed
// page-templates/recent-feed-template.php - Recent community activity

// Template Name: Main Blog Comments Feed
// page-templates/main-blog-comments-feed.php - Cross-domain blog comments
```

**Settings Page** (Hook-Based):
```php
// Settings page uses action hooks instead of template file
// inc/user-profiles/settings/settings-content.php - Content rendering
// inc/user-profiles/settings/settings-form-handler.php - Form processing
```

**bbPress Template Overrides** (bbpress/ directory):
```php
// Core templates:
// - bbpress.php (main wrapper), content-single-forum.php, content-single-topic.php
// - loop-forums.php, loop-topics.php, loop-replies.php (containers)
// - loop-single-forum-card.php, loop-single-topic-card.php, loop-single-reply-card.php (cards)
// - loop-subforums.php (subforum display)
// - form-topic.php, form-reply.php (custom forms with TinyMCE)
// - pagination-topics.php, pagination-replies.php, pagination-search.php
// - user-profile.php, user-details.php (enhanced profiles)
```

## Configuration

### Filter System

The theme provides a filter system for plugins to extend functionality without modifying theme files.

#### Avatar Menu Filter

The `ec_avatar_menu_items` filter allows plugins to add custom menu items to the user avatar dropdown menu:

```php
add_filter( 'ec_avatar_menu_items', 'my_plugin_avatar_menu_items', 10, 2 );

function my_plugin_avatar_menu_items( $menu_items, $user_id ) {
    // Example: Add custom menu items for community features
    $is_artist = get_user_meta( $user_id, 'user_is_artist', true );

    if ( $is_artist === '1' ) {
        $menu_items[] = array(
            'url'      => home_url( '/artist-dashboard/' ),
            'label'    => __( 'Artist Dashboard', 'textdomain' ),
            'priority' => 5  // Appears before settings
        );
    }

    // Add general community menu item
    $menu_items[] = array(
        'url'      => home_url( '/community-features/' ),
        'label'    => __( 'Community Features', 'textdomain' ),
        'priority' => 10
    );

    return $menu_items;
}
```

**Menu Item Structure:**
- `url` (string, required) - The menu item URL  
- `label` (string, required) - The menu item display text
- `priority` (int, optional) - Sort order (default: 10, lower = higher in menu)

### Plugin Setup

```php
// Main plugin file: extrachill_community_init() with 37 explicit require_once statements
function extrachill_community_init() {
    // Core (4): assets, bbpress-templates, nav, bbpress-spam-adjustments
    // Content (4): tinymce (2), content-filters, recent-feed
    // Social (12): upvote, mentions, badges, rank-system (2), notifications (7)
    // User Profiles (10): avatar, menu, profile, verification, settings (2), online, email, edit (2)
    // Home (3): latest-post, actions, homepage-forum-display

    // See extrachill-community.php lines 36-78 for complete explicit loading
}
add_action('plugins_loaded', 'extrachill_community_init');
```

### Performance Optimization

**bbPress Optimization**:
```php
// Dequeue default bbPress styles to prevent conflicts
function extrachill_dequeue_bbpress_default_styles() {
    wp_dequeue_style('bbp-default');
}
add_action('wp_enqueue_scripts', 'extrachill_dequeue_bbpress_default_styles', 15);
```

## AJAX Handlers

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
# 1. Plugin Loading: Verify all 37 files load via explicit require_once in extrachill_community_init()
# 2. Forum Features: Core (4), content (4), social (12), user-profiles (10), home (3)
# 3. Cross-Domain Integration: WordPress multisite authentication
# 4. bbPress Integration: Custom templates, stylesheet conflicts, functionality
# 5. JavaScript Components: 5 via assets.php, 2 independent loaders
# 6. User Management: Profiles, avatars, settings, verification, notifications
# 7. Social Features: Upvoting, mentions, badges, rank system (2 files)
# 8. Notification System: 7 notification files in inc/social/notifications/
# 9. Hook-Based Components: Homepage and settings page action hooks
# 10. User Avatar Menu: ec_avatar_menu_items filter in inc/user-profiles/user-avatar-menu.php
```

## Deployment

**Production Setup**:
1. Install plugin on community.extrachill.com WordPress
2. Activate extrachill theme
3. Activate bbPress plugin (required)
4. Activate extrachill-community plugin
5. Configure cross-domain cookies (`.extrachill.com`)
6. Run `composer install` for PHP dependencies

**Domain Configuration**:
```php
// wp-config.php additions for cross-domain
define('COOKIE_DOMAIN', '.extrachill.com');
define('EXTRACHILL_API_URL', 'https://community.extrachill.com');
```

## Architecture Notes

- **Plugin Architecture**: WordPress plugin providing community functionality that integrates with extrachill theme
- **Theme Integration**: Works seamlessly with extrachill theme on community.extrachill.com
- **Plugin Integration**: Works with other community plugins via filters and hooks
- **No Build System**: Direct file inclusion, no compilation required
- **Explicit Loading Architecture**: 39 files via direct require_once in extrachill_community_init() (NO master loader file)
- **Organized Structure**: Core (4), content (5), social (12), user-profiles (10), home (3)
- **WordPress Native**: Full compliance with WordPress plugin development standards
- **Performance Focused**: Conditional asset loading, dynamic versioning, modular CSS, 6 active JS files (1 disabled)
- **Cross-Domain Ready**: WordPress multisite native authentication exclusively (migration complete)
- **Hook-Based Components**: Homepage and settings use action hooks for extensibility
- **Filter System**: ec_avatar_menu_items filter in inc/user-profiles/user-avatar-menu.php for plugin integration

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## Author

**Chris Huber** - https://chubes.net