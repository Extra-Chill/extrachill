# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **WordPress plugin** called "Extra Chill Community" for the **Extra Chill** community platform - a music community with comprehensive forum enhancements and cross-domain authentication. The plugin provides community and forum functionality that integrates with the extrachill theme.

**Plugin Information:**
- **Name**: Extra Chill Community
- **Version**: 1.0.0
- **Text Domain**: `extra-chill-community`
- **Author**: Chris Huber
- **Author URI**: https://chubes.net
- **License**: GPL v2 or later
- **License URI**: https://www.gnu.org/licenses/gpl-2.0.html
- **Requires at least**: 5.0
- **Tested up to**: 6.4

## KNOWN ISSUES

**PSR-4 Implementation**: No PSR-4 autoloading configured in composer.json. The plugin currently uses procedural patterns throughout forum features.

**Notification System Enhancement**: Expand real-time notification capabilities and improve caching strategies.

## Key Domains & Architecture

- `community.extrachill.com` - Main platform (WordPress/bbPress) **[Uses extrachill theme + this plugin]**
- `extrachill.com` - Main website **[Uses extrachill theme + cross-domain integration]**

## Core Features

1. **Forum Features** - Comprehensive bbPress extensions with organized feature architecture
2. **Cross-Domain Authentication** - WordPress multisite native authentication system for seamless cross-domain user sessions  
3. **Social Features** - User interactions, following system, upvoting, notifications, and rank system
4. **User Management** - Custom profiles, avatars, settings, email verification, and notification system
5. **Community Templates** - Custom bbPress templates and specialized page templates

## Development Setup

### Dependencies Installation
```bash
# Navigate to plugin directory
cd /Users/chubes/Developer/Extra\ Chill\ Platform/extrachill-plugins/extrachill-community

# Install PHP dependencies (minimal - only composer structure exists)
composer install

# Note: No npm build system - uses direct file inclusion
```

### Development Notes
- **No Asset Compilation** - Direct file inclusion without npm/webpack compilation
- **Procedural Architecture** - No PSR-4 autoloading configured, uses direct procedural patterns
- **Asset Versioning** - Dynamic `filemtime()` versioning for cache management
- **Explicit Loading Pattern** - All functionality loaded via 37 direct `require_once` statements in `extrachill_community_init()` function
- **bbPress Integration** - Default stylesheet dequeuing, custom templates, enhanced functionality

### Build System
- **Universal Build Script**: Symlinked to shared build script at `../../.github/build.sh`
- **Auto-Detection**: Script auto-detects plugin from `Plugin Name:` header
- **Production Build**: Creates `/build/extrachill-community/` directory and `/build/extrachill-community.zip` file (non-versioned)
- **No Asset Compilation Required**: Plugin uses direct file inclusion (run `./build.sh` directly)
- **File Exclusion**: `.buildignore` rsync patterns exclude development files
- **Composer Integration**: Uses `composer install --no-dev` for production, restores dev dependencies after

## Architecture Principles

### 1. Plugin Architecture
- **Plugin Structure**: WordPress plugin providing community functionality that integrates with the extrachill theme
- **bbPress Integration**: Custom bbPress enhancements and forum functionality
- **Asset Management**: Conditional CSS/JS loading with dynamic versioning using `filemtime()`
- **Explicit Loading System**: All 37 feature modules loaded via direct `require_once` in `extrachill_community_init()` function (no master loader file)
- **Theme Integration**: Works with extrachill theme to provide community functionality on community.extrachill.com
- **Template System**: Provides custom bbPress templates and specialized page templates
- **Hook-Based Components**: Homepage and settings use action hooks instead of monolithic templates

### 2. Cross-Domain Session Management
- **WordPress Multisite**: Native WordPress multisite provides unified authentication across all Extra Chill domains
- **Cookie Domain**: WordPress multisite handles cross-domain authentication via `.extrachill.com` subdomain coverage

## Critical File Locations

### Core Plugin Files
- `extrachill-community.php` - Main plugin file with 37 explicit `require_once` statements in `extrachill_community_init()`
- `inc/core/assets.php` - Asset management and enqueuing system
- `inc/core/bbpress-templates.php` - bbPress template routing system
- `inc/core/bbpress-spam-adjustments.php` - bbPress spam adjustments
- `inc/core/nav.php` - Navigation functionality

### Forum Features System (inc/ structure)

**Explicit Loading Pattern** - All files loaded via direct `require_once` in `extrachill_community_init()`:

**Core (4 files loaded)**:
- `inc/core/assets.php`, `bbpress-templates.php`, `nav.php`, `bbpress-spam-adjustments.php`

**Content (4 files loaded)**:
- `inc/content/editor/tinymce-customization.php`, `editor/tinymce-image-uploads.php`
- `inc/content/content-filters.php`, `recent-feed.php`

**Social (12 files loaded)**:
- `inc/social/upvote.php`, `user-mention-api.php`, `forum-badges.php`
- `inc/social/rank-system/point-calculation.php`, `rank-system/chill-forums-rank.php`
- `inc/social/notifications/notification-bell.php`, `notification-card.php`, `notification-handler.php`
- `inc/social/notifications/notification-cleanup.php`, `capture-replies.php`, `capture-mentions.php`, `notifications-content.php`

**User Profiles (10 files loaded)**:
- `inc/user-profiles/custom-avatar.php`, `user-avatar-menu.php`, `custom-user-profile.php`, `verification.php`
- `inc/user-profiles/settings/settings-content.php`, `settings/settings-form-handler.php`
- `inc/user-profiles/online-users-count.php`, `email-change-emails.php`
- `inc/user-profiles/edit/upload-custom-avatar.php`, `edit/user-links.php`

**Home (3 files loaded)**:
- `inc/home/latest-post.php`, `actions.php`, `homepage-forum-display.php`

**Template Components (3 files - loaded via include/filters, NOT in init)**:
- `inc/home/forum-home-header.php`, `forum-homepage.php`, `recently-active.php`

**Total: 37 files explicitly loaded + 3 template components**

### Page Templates
- `page-templates/leaderboard-template.php` - User leaderboard
- `page-templates/main-blog-comments-feed.php` - Cross-domain blog comments
- `page-templates/recent-feed-template.php` - Recent community activity

### Settings System (Hook-Based)
- `inc/user-profiles/settings/settings-content.php` - Settings page content rendering via hook
- `inc/user-profiles/settings/settings-form-handler.php` - Form processing and validation

### JavaScript Architecture (7 files in inc/assets/js/)

**Loaded via assets.php (5 files)**:
- `upvote.js` - Content upvoting system (global load)
- `extrachill-mentions.js` - User mention system (bbPress only)
- `home-collapse.js` - Homepage collapse functionality (conditional)
- `utilities.js` - General utility functions (global load)
- `tinymce-image-upload.js` - TinyMCE image uploads (bbPress only)

**Loaded independently (2 files)**:
- `custom-avatar.js` - Avatar upload system (loaded by `inc/user-profiles/edit/upload-custom-avatar.php`)
- `manage-user-profile-links.js` - Profile links editor (loaded by `inc/user-profiles/edit/user-links.php`)

### CSS Files (9 files in inc/assets/css/)
- bbpress.css, home.css, leaderboard.css, notifications.css, replies-loop.css
- settings-page.css, tinymce-editor.css, topics-loop.css, user-profile.css

### bbPress Template Overrides
Custom templates in `bbpress/` directory provide enhanced forum functionality:
- `bbpress.php` - Main bbPress wrapper template
- `content-single-forum.php` - Single forum view with subforum support
- `content-single-topic.php` - Single topic view with custom layout
- `loop-forums.php` - Forum list container
- `loop-topics.php` - Topic list container
- `loop-replies.php` - Reply list container
- `loop-single-forum-card.php` - Individual forum card rendering
- `loop-single-topic-card.php` - Individual topic card rendering
- `loop-single-reply-card.php` - Individual reply card rendering
- `loop-subforums.php` - Subforum display component
- `form-topic.php`, `form-reply.php` - Custom form templates with TinyMCE
- `pagination-topics.php`, `pagination-replies.php`, `pagination-search.php` - Custom pagination
- `user-profile.php`, `user-details.php` - Enhanced user profile templates

## Development Guidelines

### Plugin Development Principles
1. **Plugin Architecture** - WordPress plugin that integrates with the extrachill theme to provide community functionality
2. **WordPress Standards** - Full compliance with WordPress plugin development guidelines and coding standards
3. **Plugin Initialization** - Uses plugin initialization hooks for proper setup
4. **Modular Asset Loading** - Context-aware CSS/JS enqueuing with bbPress integration
5. **Theme Integration** - Works seamlessly with extrachill theme on community.extrachill.com
6. **bbPress Enhancement** - Extends bbPress functionality with custom features
7. **Cross-Domain Integration** - Provides multisite authentication and data sharing
8. **Performance Optimization** - Conditional loading and selective script enqueuing

### Forum Features Architecture
1. **Explicit Loading Pattern** - 37 files loaded via direct `require_once` in `extrachill_community_init()` function
2. **Organized Structure** - Features grouped by functionality: core (4), content (4), social (12), user-profiles (10), home (3)
3. **Conditional Loading** - Context-aware CSS/JS loading for performance
4. **bbPress Integration** - Custom templates via `inc/core/bbpress-templates.php` routing
5. **Hook-Based Components** - Homepage and settings use action hooks for extensibility
6. **Template Partials** - 3 home template components loaded via include/filters (not in init)

### Code Patterns
- **WordPress Coding Standards** - Full compliance with plugin development best practices
- **Plugin Architecture** - Community functionality that integrates with extrachill theme
- **bbPress Enhancement** - Custom hooks, filters, and functionality extensions
- **Asset Management** - Dynamic versioning with `filemtime()`, selective loading, and conflict prevention
- **Theme Integration** - Seamless integration with extrachill theme on community.extrachill.com
- **Procedural Code Organization** - No PSR-4 autoloading configured, uses direct function-based patterns
- **Security Implementation** - Proper escaping, nonce verification, and input sanitization
- **Performance Focus** - Modular CSS/JS loading and conditional script enqueuing
- **Cross-Domain Functionality** - Multisite authentication and data sharing capabilities

### JavaScript Architecture Principles
- **Modular Design** - 7 JS files in `inc/assets/js/` with specialized functionality domains
- **Mixed Loading** - 5 files via assets.php centrally, 2 loaded independently by their feature modules
- **jQuery Dependencies** - Proper dependency management across all custom scripts
- **Context-Aware Loading** - Conditional script enqueuing based on page template/context
- **Dynamic Versioning** - `filemtime()` versioning for cache busting
- **Forum Integration** - Custom bbPress enhancements for editor, social features, and UI

## Dependencies

### PHP
- **WordPress** 5.0+ (with bbPress required)
- **Composer Dependencies**: None (minimal composer.json structure only)

### JavaScript
- **Direct File Inclusion** - No build system, direct file loading
- **jQuery Dependencies** - All custom scripts depend on jQuery
- **7 Files Total** - 5 via assets.php centrally, 2 via feature modules independently
- **Dynamic Versioning** - `filemtime()` cache busting

## Database Tables

### Key Meta Fields
- `_show_on_homepage` - Boolean meta field controlling forum display on homepage
- `_user_profile_dynamic_links` - User profile social links
- `ec_custom_title` - User custom titles (default: 'Extra Chillian')
- `extrachill_notifications` - User notification data cache
- `user_is_artist` - User role flag for artist accounts
- `user_is_professional` - User role flag for professional accounts

## Filter System

### Avatar Menu Filter

The theme provides the `ec_avatar_menu_items` filter to allow plugins to add custom menu items to the user avatar dropdown menu in the header.

**Filter Usage:**
```php
add_filter( 'ec_avatar_menu_items', 'my_plugin_avatar_menu_items', 10, 2 );

function my_plugin_avatar_menu_items( $menu_items, $user_id ) {
    // Example: Add custom menu item for community features
    $menu_items[] = array(
        'url'      => home_url( '/community-settings/' ),
        'label'    => __( 'Community Settings', 'textdomain' ),
        'priority' => 10
    );

    return $menu_items;
}
```

**Menu Item Structure:**
- `url` (string, required) - The menu item URL
- `label` (string, required) - The menu item text
- `priority` (int, optional) - Sort priority (default: 10, lower numbers appear first)

**Integration Pattern:**
The filter is applied in `inc/user-profiles/user-avatar-menu.php` at line 40 between core profile menu items and settings/logout items, allowing plugins to inject custom functionality without modifying theme files.

## Current Status

The plugin operates as a production WordPress plugin serving the Extra Chill community alongside the extrachill theme. Core functionality includes forum enhancements, WordPress multisite authentication, and bbPress integration. The plugin provides community functionality for community.extrachill.com while the extrachill theme handles the visual presentation.

**Migration Complete**: The community functionality has been successfully transitioned from a standalone theme to a plugin-based architecture. All theme files (header.php, footer.php, index.php, functions.php) have been removed. The plugin now provides clean forum functionality that integrates with the extrachill theme.

**Modern Architecture**: The plugin uses hook-based components for homepage and settings pages, organized file structure in `inc/` directory, and WordPress multisite native authentication. All assets moved to `inc/assets/css/` and `inc/assets/js/` directories.

**Plugin Integration**: Other plugins can use the `ec_avatar_menu_items` filter to add custom menu items to the user avatar dropdown, maintaining seamless navigation between community and plugin-specific functions.

## Cross-Domain Authentication Flow

### WordPress Multisite Native Authentication
1. User logs in on any Extra Chill domain
2. WordPress multisite automatically provides authentication across all `.extrachill.com` subdomains
3. Native WordPress user sessions handle cross-domain authentication
4. Users remain logged in across all Extra Chill properties without additional validation

**Migration Complete**: The plugin now uses WordPress multisite native authentication exclusively. All custom session token functionality has been removed. Cross-domain integration relies entirely on WordPress core multisite capabilities for authentication.