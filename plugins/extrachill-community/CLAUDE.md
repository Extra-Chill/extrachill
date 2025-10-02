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
- **Organized Architecture** - Forum features organized in `inc/` structure with master loader at `inc/includes.php`
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
- **Code Organization**: Forum features organized in `inc/` structure with master loader at `inc/includes.php`
- **Theme Integration**: Works with extrachill theme to provide community functionality on community.extrachill.com
- **Template System**: Provides custom bbPress templates and specialized page templates
- **Hook-Based Components**: Homepage and settings use action hooks instead of monolithic templates

### 2. Cross-Domain Session Management
- **WordPress Multisite**: Native WordPress multisite provides unified authentication across all Extra Chill domains
- **Cookie Domain**: WordPress multisite handles cross-domain authentication via `.extrachill.com` subdomain coverage
- **Seamless Comments**: Cross-domain commenting integration maintained in `extrachill-integration/seamless-comments.php`

## Critical File Locations

### Core Plugin Files
- `extrachill-community.php` - Main plugin file with plugin header and initialization
- `inc/core/assets.php` - Asset management and enqueuing system
- `inc/core/bbpress-templates.php` - bbPress template routing system
- `inc/includes.php` - Master loader for all forum functionality
- `style.css` - Main plugin stylesheet

### Forum Features System (inc/ structure)
- `inc/admin/` - bbPress spam adjustments, forum sections
- `inc/content/` - Editor, queries, breadcrumbs, sorting, notifications, dynamic menu
- `inc/social/` - Upvoting, mentions, badges, notifications, rank system
- `inc/users/` - Custom avatars, profiles, verification, online tracking, settings
- `inc/home/` - Homepage components (header, recently active, homepage template)

### Page Templates
- `page-templates/leaderboard-template.php` - User leaderboard
- `page-templates/main-blog-comments-feed.php` - Cross-domain blog comments
- `page-templates/notifications-feed.php` - User notifications system
- `page-templates/recent-feed-template.php` - Recent community activity

### Settings System (Hook-Based)
- `inc/users/settings/settings-content.php` - Settings page content rendering via hook
- `inc/users/settings/settings-form-handler.php` - Form processing and validation
- `inc/users/email-change-emails.php` - Email change verification and confirmation emails

### Cross-Domain Integration
- `extrachill-integration/seamless-comments.php` - Cross-domain commenting with multisite integration

### JavaScript Architecture (12 files in inc/assets/js/)
- custom-avatar.js, extrachill-follow.js, extrachill-mentions.js, home-collapse.js
- manage-user-profile-links.js, nav-menu.js, quote.js, sorting.js
- tinymce-image-upload.js, topic-quick-reply.js, upvote.js, utilities.js

### CSS Files (9 files in inc/assets/css/)
- home.css, leaderboard.css, notifications.css, replies-loop.css, settings-page.css
- tinymce-editor.css, topic-quick-reply.css, topics-loop.css, user-profile.css

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
1. **Master Loader** - `inc/includes.php` loads all forum functionality
2. **Organized Structure** - Features grouped in `inc/` by functionality (admin, content, social, users, home)
3. **Conditional Loading** - Context-aware CSS/JS loading for performance
4. **bbPress Integration** - Custom templates via `inc/core/bbpress-templates.php` routing
5. **Hook-Based Components** - Homepage and settings use action hooks for extensibility

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
- **Modular Design** - 12 specialized JS files in `inc/assets/js/` for specific functionality domains
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
- **12 Specialized Files** - All files in `inc/assets/js/` directory
- **FontAwesome** 6.5.1 via CDN
- **Dynamic Versioning** - `filemtime()` cache busting

## Database Tables

### Custom Tables
- `user_session_tokens` (with wp_ prefix) - Cross-domain authentication

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
The filter is applied in `forum-features/content/notification-bell-avatar.php` between core profile menu items and settings/logout items, allowing plugins to inject custom functionality without modifying theme files.

## Current Status

The plugin operates as a production WordPress plugin serving the Extra Chill community alongside the extrachill theme. Core functionality includes forum enhancements, cross-domain authentication, and bbPress integration. The plugin provides community functionality for community.extrachill.com while the extrachill theme handles the visual presentation.

**Architecture Transition**: The community functionality has been transitioned from a standalone theme to a plugin-based architecture. This plugin now works with the extrachill theme to provide community features on community.extrachill.com.

**Plugin Integration**: Plugins can use the `ec_avatar_menu_items` filter to add custom menu items to the user avatar dropdown, maintaining seamless navigation between community and plugin-specific functions.

## Cross-Domain Authentication Flow

### Current (WordPress Multisite)
1. User logs in on any Extra Chill domain
2. WordPress multisite automatically provides authentication across all `.extrachill.com` subdomains
3. Native WordPress user sessions handle cross-domain authentication
4. Users remain logged in across all Extra Chill properties without additional validation

### Legacy (Maintained for Compatibility)
1. **Legacy Path**: User logs in via custom REST API endpoints
2. **Legacy Path**: Session token generated in `user_session_tokens` table
3. **Legacy Path**: Custom cookie set for `.extrachill.com` domain
4. **Legacy Path**: Auto-login triggered via `auto_login_via_session_token()`
5. **Legacy Path**: External requests validated using Authorization header

**Migration Status**: The system is transitioning from custom session tokens to WordPress multisite native authentication. Legacy endpoints are maintained during the migration period to ensure backward compatibility.

This hybrid approach enables seamless user experience across the entire Extra Chill ecosystem while maintaining security, performance, and compatibility during the multisite migration.