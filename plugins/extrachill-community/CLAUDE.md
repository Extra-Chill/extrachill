# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **WordPress theme** called "Extra Chill Community" for the **ExtraChill** community platform - a music community with comprehensive forum enhancements and cross-domain authentication. The theme focuses purely on community and forum functionality. Artist profile and link page features have been fully migrated to the `extrachill-artist-platform` plugin.

**Theme Information:**
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

**PSR-4 Implementation**: Composer autoloader configured for `Chubes\Extrachill\` namespace. The `src/` directory exists but is currently empty (contains only .gitkeep).

## FUTURE PLANS

**PSR-4 Architecture**: Implement proper `src/` directory structure with classes to replace procedural patterns in forum features.

**Performance Optimization**: Continue modular CSS/JS loading refinements and font system improvements.

## Key Domains & Architecture

- `community.extrachill.com` - Main platform (WordPress/bbPress) **[This theme]**
- `extrachill.com` - Main website **[Cross-domain integration]**

## Core Features

1. **Forum Features** - Comprehensive bbPress extensions (38+ organized features)
2. **Cross-Domain Authentication** - Session token system across all ExtraChill domains  
3. **Social Features** - User interactions, following system, reputation system within forums
4. **Community Templates** - Custom bbPress templates and page templates for community functionality

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
- **PSR-4 Ready** - Composer autoloader configured (no `src/` directory implemented)
- **Asset Versioning** - Dynamic `filemtime()` versioning for cache management
- **Organized Architecture** - Forum features in structured subdirectories with master loader
- **Font System** - Custom font-face declarations with inheritance optimization
- **bbPress Integration** - Default stylesheet dequeuing, custom templates, enhanced functionality

## Architecture Principles

### 1. Hybrid Theme Structure
- **Theme Setup**: Full WordPress theme with `extra_chill_community_setup()` function
- **WordPress Features**: Supports automatic-feed-links, title-tag, post-thumbnails, custom-logo, HTML5 markup, customize-selective-refresh-widgets
- **Navigation Menus**: Primary, Footer, and Footer Extra menus plus 5 additional footer menu areas registered with proper text domain
- **Widget Areas**: Custom sidebar plus 5 footer widget areas with proper escaping and structure
- **Asset Management**: Conditional CSS/JS loading with dynamic versioning using `filemtime()`
- **Template Hierarchy**: Includes required `index.php` template file as fallback
- **Code Organization**: Forum features organized in `forum-features/` directory with master loader
- **bbPress Integration**: Custom bbPress stylesheet dequeuing (`wp_dequeue_style('bbp-default')`) to prevent conflicts
- **Independent Templates**: All page templates use native WordPress theme structure without external dependencies

### 2. Cross-Domain Session Management
- **Session Tokens**: Custom `user_session_tokens` table (with wp_ prefix) with 6-month expiration
- **Cookie Domain**: `.extrachill.com` covers all subdomains
- **Auto-Login**: Triggered via `auto_login_via_session_token()`

## Critical File Locations

### Core Theme Files
- `functions.php` - Theme setup, WordPress features, asset loading
- `index.php` - Required WordPress template file (fallback)
- `style.css` - Main theme stylesheet with header and font declarations

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
- `extrachill-integration/session-tokens.php` - Cross-domain session management
- `extrachill-integration/validate-session.php` - Token validation
- `extrachill-integration/seamless-comments.php` - Cross-domain commenting
- `login/register.php` - Registration system with email verification
- `login/login.php` - Custom login system
- `login/login-includes.php` - Login system includes
- `login/email-change-emails.php` - Email change functionality

### Forum Features Architecture (38+ Features Total)
- **Admin Features** (9): Moderation, forum management, email notifications (`admin/`)
- **Content Features** (13): Embeds, editor customization, queries, processing (`content/`)
- **Social Features** (10): Following, upvoting, notifications, mentions, rank system (`social/`)
- **User Features** (6): Profiles, avatars, verification, settings (`users/`)
- **Master Loader**: `forum-features/forum-features.php` loads all functionality with comprehensive documentation
- **Asset Organization**: JavaScript (4 files) and CSS organized within feature subdirectories

### JavaScript Architecture (17 total files: 13 in js/ + 4 in forum-features)
- **Core Utilities**: `js/utilities.js` - Shared functionality across components
- **Social Features**: `forum-features/social/js/` (4 files) - Following, mentions, upvoting, admin tools
- **Forum Enhancements**: `js/quote.js`, `topic-quick-reply.js`, `tinymce-image-upload.js` - bbPress editor extensions
- **UI Components**: `js/shared-tabs.js`, `nav-menu.js`, `home-collapse.js` - Interface elements
- **User Management**: `js/custom-avatar.js`, `manage-user-profile-links.js` - User profile functionality
- **Authentication**: `js/seamless-login.js`, `seamless-comments.js` - Cross-domain integration
- **Content Systems**: `js/sorting.js`, `submit-community-comments.js` - Dynamic content management
- **Login System**: `login/js/` (2 files) - Authentication UI and join flow

### Asset Enqueuing System
- **Main Stylesheet**: `extra-chill-community-style` - Primary theme styles with root CSS import system
- **bbPress Optimization**: `extrachill_dequeue_bbpress_default_styles()` removes default bbPress styles at priority 15
- **Modular CSS**: Context-specific loading via `modular_bbpress_styles()` function
- **Font System**: Custom WilcoLoftSans and Lobster font-face declarations with inheritance optimization
- **Content Width**: Responsive overrides with flex-wrap patterns for mobile optimization
- **JavaScript Assets**: 17 specialized JS files including utilities, social features, forum enhancements, and media upload
- **External Dependencies**: FontAwesome 6.5.1 via CDN
- **Dynamic Versioning**: All assets use `filemtime()` for cache busting
- **Conditional Loading**: Context-aware asset loading for optimal performance
- **Script Dependencies**: Proper jQuery dependency management across all custom scripts

## Development Guidelines

### Theme Development Principles
1. **Hybrid Architecture** - WordPress theme with mixed independence and selective legacy dependencies
2. **WordPress Standards** - Full compliance with WordPress theme development guidelines and coding standards
3. **Theme Setup Hook** - Uses `after_setup_theme` action for proper theme initialization
4. **Modular Asset Loading** - Context-aware CSS/JS enqueuing with bbPress stylesheet conflict prevention
5. **Template Hierarchy** - WordPress template structure with required `index.php` as fallback
6. **Widget System** - Custom sidebar plus 5 footer widget areas via `widgets_init` action
7. **Navigation System** - 7 registered navigation menu areas with proper escaping and text domain support
8. **Performance Optimization** - Font inheritance system, responsive overrides, and selective script loading

### Forum Features Architecture
1. **Master Loader** - `forum-features/forum-features.php` loads all forum functionality
2. **Organized Structure** - Features grouped by functionality (admin, content, social, users)
3. **Conditional Loading** - Context-aware CSS/JS loading for performance
4. **bbPress Integration** - Custom templates and hooks for enhanced functionality

### Code Patterns
- **WordPress Coding Standards** - Full compliance with theme development best practices
- **Theme Setup Function** - `extra_chill_community_setup()` with comprehensive feature support
- **WordPress Feature Support** - Complete `add_theme_support()` implementation for core features
- **Navigation System** - Multiple menu areas via `register_nav_menus()` with proper text domain
- **Widget Areas** - Multiple footer and sidebar areas via `register_sidebar()` with proper escaping
- **Asset Management** - Dynamic versioning with `filemtime()`, selective loading, and conflict prevention
- **bbPress Integration** - Custom hooks, filters, and stylesheet dequeuing for seamless integration
- **PSR-4 Autoloading** - Composer-managed class autoloading with `Chubes\Extrachill\` namespace
- **Security Implementation** - Proper escaping, nonce verification, and input sanitization
- **Performance Focus** - Modular CSS/JS loading, font optimization, and responsive design patterns

### JavaScript Architecture Principles
- **Modular Design** - 17 specialized JS files for specific functionality domains
- **jQuery Dependencies** - Proper dependency management across all custom scripts  
- **Context-Aware Loading** - Conditional script enqueuing based on page template/context
- **Cross-Domain Integration** - Seamless login and comment systems across domains
- **Dynamic Versioning** - `filemtime()` versioning for cache busting
- **Forum Integration** - Custom bbPress enhancements for editor, social features, and UI

## Dependencies

### PHP
- **WordPress** 5.0+ (with bbPress required)
- **Composer Dependencies**: QR code generation (`endroid/qr-code`) and PSR-4 autoloading configured

### JavaScript
- **Direct File Inclusion** - No build system, direct file loading
- **jQuery Dependencies** - All custom scripts depend on jQuery
- **17 Specialized Files** - Modular architecture with specific functionality domains
- **FontAwesome** 6.5.1 via CDN
- **Dynamic Versioning** - `filemtime()` cache busting

## Database Tables

### Custom Tables
- `user_session_tokens` (with wp_ prefix) - Cross-domain authentication

### Key Meta Fields
- `_show_on_homepage` - Boolean meta field controlling forum display on homepage
- `_user_profile_dynamic_links` - User profile social links
- `ec_custom_title` - User custom titles (default: 'Extra Chillian')

## Current Status

The theme operates as a production WordPress theme serving the ExtraChill community. Core functionality includes forum enhancements, cross-domain authentication, and bbPress integration. All text domain references have been successfully migrated from `generatepress_child` to `extra-chill-community`. 

**Migration Complete**: All artist platform functionality (band profiles, link pages, CPTs, admin interfaces, data management) has been completely removed from the theme and migrated to the `extrachill-artist-platform` plugin. The theme now focuses exclusively on community forum features.

## Cross-Domain Authentication Flow

1. User logs in on any domain via REST API
2. Session token generated in `user_session_tokens` (with wp_ prefix)
3. Cookie set for `.extrachill.com` domain
4. Auto-login triggered on subsequent visits
5. External requests validated using Authorization header

This system enables seamless user experience across the entire ExtraChill ecosystem while maintaining security and performance.