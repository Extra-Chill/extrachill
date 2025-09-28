# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **WordPress plugin** called "Extra Chill Community" for the **Extra Chill** community platform - a music community with comprehensive forum enhancements and cross-domain authentication. The plugin provides community and forum functionality that integrates with the extrachill theme. Artist profile and link page features are handled by the `extrachill-artist-platform` plugin.

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

## FUTURE PLANS

**PSR-4 Architecture**: Implement PSR-4 autoloading configuration in composer.json and create `src/` directory structure with classes to replace procedural patterns in forum features.

**Performance Optimization**: Continue modular CSS/JS loading refinements and font system improvements.

**Notification System Enhancement**: Expand real-time notification capabilities and improve caching strategies.

## Key Domains & Architecture

- `community.extrachill.com` - Main platform (WordPress/bbPress) **[Uses extrachill theme + this plugin]**
- `extrachill.com` - Main website **[Uses extrachill theme + cross-domain integration]**

## Core Features

1. **Forum Features** - Comprehensive bbPress extensions with organized feature architecture
2. **Cross-Domain Authentication** - WordPress multisite native authentication system (migrating from legacy session tokens)  
3. **Social Features** - User interactions, following system, upvoting, notifications, and rank system
4. **User Management** - Custom profiles, avatars, settings, email verification, and notification system
5. **Community Templates** - Custom bbPress templates and specialized page templates

## Development Setup

### Dependencies Installation
```bash
# Navigate to theme directory
cd /Users/chubes/Local\ Sites/community-stage/app/public/wp-content/themes/extrachill-community

# Install PHP dependencies
composer install

# Note: No npm build system - uses direct file inclusion
```

### Development Notes
- **No Build System** - Direct file inclusion without compilation
- **Procedural Architecture** - No PSR-4 autoloading configured, uses direct procedural patterns
- **Asset Versioning** - Dynamic `filemtime()` versioning for cache management
- **Organized Architecture** - Forum features in structured subdirectories with master loader
- **Font System** - Custom font-face declarations with inheritance optimization
- **bbPress Integration** - Default stylesheet dequeuing, custom templates, enhanced functionality

## Architecture Principles

### 1. Plugin Architecture
- **Plugin Structure**: WordPress plugin providing community functionality that integrates with the extrachill theme
- **bbPress Integration**: Custom bbPress enhancements and forum functionality
- **Asset Management**: Conditional CSS/JS loading with dynamic versioning using `filemtime()`
- **Code Organization**: Forum features organized in `forum-features/` directory with master loader
- **Theme Integration**: Works with extrachill theme to provide community functionality on community.extrachill.com
- **Template System**: Provides custom templates and functionality that extend the base theme

### 2. Cross-Domain Session Management
- **WordPress Multisite**: Native WordPress multisite provides unified authentication across all Extra Chill domains
- **Legacy Session Tokens**: Custom `user_session_tokens` table (maintained for backward compatibility during migration)
- **Cookie Domain**: WordPress multisite handles cross-domain authentication via `.extrachill.com` subdomain coverage
- **Auto-Login**: Native WordPress multisite login replaces custom `auto_login_via_session_token()` (legacy method maintained for compatibility)

## Critical File Locations

### Core Theme Files
- `functions.php` - Theme setup, WordPress features, asset loading
- `index.php` - Required WordPress template file (fallback)
- `style.css` - Main theme stylesheet with header and font declarations
- `header.php` - Theme header with notification bell and user avatar system
- `footer.php` - Theme footer with widget areas and navigation
- `sidebar.php` - Custom sidebar implementation

### Forum Features System
- `forum-features/forum-features.php` - Master loader for all forum functionality
- `forum-features/admin/` - Moderation, management, notifications
- `forum-features/content/` - Embeds, editor, queries, processing
- `forum-features/social/` - Interactions, following, reputation
- `forum-features/users/` - Profiles, settings, verification

### Page Templates
- `page-templates/following-feed-template.php` - User following feed
- `page-templates/leaderboard-template.php` - User leaderboard
- `page-templates/login-register-template.php` - Authentication with join flow modal
- `page-templates/main-blog-comments-feed.php` - Cross-domain blog comments
- `page-templates/notifications-feed.php` - User notifications system
- `page-templates/recent-feed-template.php` - Recent community activity
- `page-templates/settings-page.php` - User account settings

### Authentication & Integration
- `extrachill-integration/session-tokens.php` - **Legacy**: Cross-domain session management (maintained for compatibility)
- `extrachill-integration/seamless-comments.php` - Cross-domain commenting with multisite integration
- `login/register.php` - Registration system with email verification
- `login/login.php` - Custom login system integrated with WordPress multisite authentication
- `login/login-includes.php` - Login system includes with multisite support
- `login/email-change-emails.php` - Email change verification and confirmation emails

### Forum Features Architecture
- **Admin Features**: Moderation tools, forum management, email notifications, restricted forums (`admin/`)
- **Content Features**: Bandcamp embeds, editor customization, queries, breadcrumbs, pagination (`content/`)
- **Social Features**: Following system, upvoting, notifications, mentions, rank system with points calculation (`social/`)
- **User Features**: Custom profiles, avatars, verification, settings, online user tracking (`users/`)
- **Master Loader**: `forum-features/forum-features.php` loads all functionality with comprehensive documentation
- **Asset Organization**: Specialized JavaScript files and CSS organized within feature subdirectories

### JavaScript Architecture (20 total files)
- **Core Utilities**: `js/utilities.js` - Shared functionality across components
- **Main JS Directory** (`js/`): 12 files - Core functionality including custom-avatar.js, manage-user-profile-links.js, quote.js, seamless-comments.js, seamless-login.js, shared-tabs.js, submit-community-comments.js, tinymce-image-upload.js, topic-quick-reply.js, sorting.js, home-collapse.js, nav-menu.js
- **Social Features** (`forum-features/social/js/`): 4 files - extrachill-follow.js, upvote.js, extrachill-mentions.js, extrachill_admin.js (rank system)
- **Login System** (`login/js/`): 2 files - login-register-tabs.js, join-flow-ui.js
- **bbPress Extensions** (`bbpress/autosave/`): 1 file - plugin.min.js
- **Note**: User mentions functionality consolidated to single file in `forum-features/social/js/extrachill-mentions.js`

### Asset Enqueuing System
- **Main Stylesheet**: `extra-chill-community-style` - Primary theme styles with root CSS import system
- **bbPress Optimization**: `extrachill_dequeue_bbpress_default_styles()` removes default bbPress styles at priority 15
- **Modular CSS**: Context-specific loading via `modular_bbpress_styles()` function
- **Font System**: Custom WilcoLoftSans and Lobster font-face declarations with inheritance optimization
- **Content Width**: Responsive overrides with flex-wrap patterns for mobile optimization
- **JavaScript Assets**: 21 specialized JS files including utilities, social features, forum enhancements, and media upload
- **External Dependencies**: FontAwesome 6.5.1 via CDN
- **Dynamic Versioning**: All assets use `filemtime()` for cache busting
- **Conditional Loading**: Context-aware asset loading for optimal performance
- **Script Dependencies**: Proper jQuery dependency management across all custom scripts

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
1. **Master Loader** - `forum-features/forum-features.php` loads all forum functionality
2. **Organized Structure** - Features grouped by functionality (admin, content, social, users)
3. **Conditional Loading** - Context-aware CSS/JS loading for performance
4. **bbPress Integration** - Custom templates and hooks for enhanced functionality

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
- **Modular Design** - 21 specialized JS files for specific functionality domains
- **jQuery Dependencies** - Proper dependency management across all custom scripts  
- **Context-Aware Loading** - Conditional script enqueuing based on page template/context
- **Cross-Domain Integration** - Seamless login and comment systems across domains
- **Dynamic Versioning** - `filemtime()` versioning for cache busting
- **Forum Integration** - Custom bbPress enhancements for editor, social features, and UI

## Dependencies

### PHP
- **WordPress** 5.0+ (with bbPress required)
- **Composer Dependencies**: QR code generation (`endroid/qr-code`) only

### JavaScript
- **Direct File Inclusion** - No build system, direct file loading
- **jQuery Dependencies** - All custom scripts depend on jQuery
- **21 Specialized Files** - Modular architecture with specific functionality domains
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
- `_artist_profile_ids` - Cross-reference to artist platform plugin data
- `user_is_artist` - User role flag for artist accounts
- `user_is_professional` - User role flag for professional accounts

## Filter System

### Avatar Menu Filter

The theme provides the `ec_avatar_menu_items` filter to allow plugins to add custom menu items to the user avatar dropdown menu in the header.

**Filter Usage:**
```php
add_filter( 'ec_avatar_menu_items', 'my_plugin_avatar_menu_items', 10, 2 );

function my_plugin_avatar_menu_items( $menu_items, $user_id ) {
    $menu_items[] = array(
        'url'      => home_url( '/custom-page/' ),
        'label'    => __( 'Custom Menu Item', 'textdomain' ),
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

**Artist Platform Integration**: Plugins can use the `ec_avatar_menu_items` filter to add custom menu items to the user avatar dropdown, maintaining seamless navigation between community and plugin-specific functions.

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