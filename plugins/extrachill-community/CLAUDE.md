# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **standalone WordPress theme** called "Extra Chill Community" hosting the **ExtraChill** community platform - a comprehensive band platform and link page management system for musicians. The project serves a music community across multiple domains with seamless cross-domain authentication.

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

**Text Domain Migration**: 497 `generatepress_child` text domain references exist across 54 files requiring systematic update to `extra-chill-community`.

**Mixed Architecture**: Theme contains GeneratePress template dependencies in `page-templates/login-register-template.php` and `page-templates/settings-page.php` using `generate_content_class()` function calls.

## FUTURE PLANS

**Text Domain Migration**: Systematic replacement of 497 `generatepress_child` references across 54 files with `extra-chill-community`.

**Template Independence**: Remove GeneratePress function dependencies in login-register and settings page templates.

**Performance Optimization**: Continue modular CSS/JS loading refinements and font system improvements.

## Key Domains & Architecture

- `community.extrachill.com` - Main platform (WordPress/bbPress)
- `extrachill.link` - Public link pages ("link in bio" service)
- `extrch.co` - Short domain variant
- `extrachill.com` - Main website

## Core Features

1. **Band Platform** - Custom `band_profile` CPT with associated hidden bbPress forums
2. **Link Page System** - Customizable "link in bio" service (`band_link_page` CPT)
3. **Cross-Domain Authentication** - Seamless login across all domains
4. **Forum Integration** - bbPress-based community forums
5. **Analytics & Tracking** - Custom analytics for link pages
6. **Email Subscriber Management** - Unified consent system

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
- **No Build System** - Direct file inclusion without webpack or compilation
- **PSR-4 Autoloading** - Composer autoloader with `Chubes\Extrachill\` namespace
- **Asset Versioning** - Dynamic `filemtime()` versioning for cache management
- **Modular Architecture** - 19 JavaScript files, conditional CSS loading
- **Font System** - Custom font-face declarations with inheritance optimization
- **bbPress Optimization** - Default stylesheet dequeuing with custom styling

## Architecture Principles

### 1. Hybrid Theme Structure
- **Theme Setup**: Full WordPress theme with `extra_chill_community_setup()` function
- **WordPress Features**: Supports automatic-feed-links, title-tag, post-thumbnails, custom-logo, HTML5 markup, customize-selective-refresh-widgets
- **Navigation Menus**: Primary, Footer, and Footer Extra menus plus 5 additional footer menu areas registered with proper text domain
- **Widget Areas**: Custom sidebar plus 5 footer widget areas with proper escaping and structure
- **Asset Management**: Conditional CSS/JS loading with dynamic versioning using `filemtime()`
- **Template Hierarchy**: Includes required `index.php` template file as fallback
- **Code Organization**: Features organized in `band-platform/` directory with centralized includes
- **bbPress Integration**: Custom bbPress stylesheet dequeuing (`wp_dequeue_style('bbp-default')`) to prevent conflicts
- **Mixed Dependencies**: Some templates retain GeneratePress function calls for layout compatibility

### 2. Single Source of Truth
- **Link Page Rendering**: `band-platform/extrch.co-link-page/extrch-link-page-template.php` is canonical template
- **Data Provider**: `LinkPageDataProvider.php` handles all data operations
- **CSS Variables**: Style tag in DOM is sole source of truth for live preview

### 3. Cross-Domain Session Management
- **Session Tokens**: Custom `wp_user_session_tokens` table with 6-month expiration
- **Cookie Domain**: `.extrachill.com` covers all subdomains
- **Auto-Login**: Triggered via `auto_login_via_session_token()`

## Critical File Locations

### Core Theme Files
- `functions.php` - Main theme functions, WordPress feature support, and modular asset loading
- `index.php` - Required WordPress template file (fallback)
- `style.css` - Main theme stylesheet with header information and font-face declarations
- `bbpress-customization.php` - bbPress modifications and custom hooks

### Band Platform Core
- `band-platform/cpt-band-profile.php` - Band profile custom post type registration
- `band-platform/band-forums.php` - Automatic forum creation and management
- `band-platform/band-platform-includes.php` - Centralized feature includes
- `page-templates/manage-band-profile.php` - Band management interface

### Link Page System (18 files)
- `band-platform/extrch.co-link-page/extrch-link-page-template.php` - Canonical template
- `band-platform/extrch.co-link-page/cpt-band-link-page.php` - Custom post type
- `band-platform/extrch.co-link-page/link-page-form-handler.php` - Save processing
- `band-platform/extrch.co-link-page/ajax-handlers.php` - AJAX functionality
- `single-band_link_page.php` - Public link page display
- `page-templates/manage-link-page.php` - Link page management interface

### Page Templates (10 files)
- `page-templates/login-register-template.php` - Authentication interface (uses GeneratePress functions)
- `page-templates/settings-page.php` - User settings (uses GeneratePress functions)
- `page-templates/notifications-feed.php` - Notifications system
- `page-templates/band-directory.php` - Band listings
- `page-templates/leaderboard-template.php` - Community leaderboard

### Authentication & Integration
- `extrachill-integration/session-tokens.php` - Cross-domain session management
- `extrachill-integration/validate-session.php` - Token validation
- `extrachill-integration/seamless-comments.php` - Cross-domain commenting
- `login/register.php` - Registration system

### JavaScript Architecture
- **Core Utilities**: `utilities.js` - Shared functionality across components
- **Social Features**: `extrachill-follow.js`, `extrachill-mentions.js` - User interaction systems
- **Forum Enhancements**: `upvote.js`, `quote.js`, `topic-quick-reply.js` - bbPress extensions
- **UI Components**: `shared-tabs.js`, `custom-avatar.js`, `nav-menu.js` - Interface elements
- **Form Management**: `manage-band-profiles.js`, `manage-user-profile-links.js` - Data handling
- **Content Systems**: `sorting.js`, `home-collapse.js` - Dynamic content management
- **Media Upload**: `extrachill-image-upload.js`, `tinymce-image-upload.js` - File handling
- **Cross-Domain**: `seamless-login.js`, `seamless-comments.js` - Authentication integration
- **Legacy Scripts**: `upvote-1494.js`, `wp_surgeon_admin.js` - Deprecated functionality

### Asset Enqueuing System
- **Main Stylesheet**: `extra-chill-community-style` - Primary theme styles with root CSS import system
- **bbPress Optimization**: `extrachill_dequeue_bbpress_default_styles()` removes default bbPress styles at priority 15
- **Modular CSS**: Context-specific loading via `modular_bbpress_styles()` function
- **Font System**: Custom WilcoLoftSans and Lobster font-face declarations with inheritance optimization
- **Content Width**: Responsive overrides with flex-wrap patterns for mobile optimization
- **JavaScript Assets**: 19 specialized JS files including utilities, social features, forum enhancements, and media upload
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

### Data Flow Principles
1. **PHP renders initial state** from database
2. **JavaScript listens for changes** and updates preview
3. **DOM serves as single source** of truth during editing
4. **Hidden inputs updated only before save** for PHP processing

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
- **Modular Design** - 19 specialized JS files for specific functionality domains
- **jQuery Dependencies** - Proper dependency management across all custom scripts
- **DOM-Based State** - No persistent JavaScript state, always read from DOM
- **Conditional Loading** - Context-aware script enqueuing for performance
- **Legacy Management** - Deprecated scripts maintained for backward compatibility
- **Cross-Domain Integration** - Seamless login and comment systems across domains
- **Dynamic Versioning** - `filemtime()` versioning for cache busting

## Dependencies

### PHP
- **WordPress** (with bbPress)
- **QR Code Generation**: `endroid/qr-code` ^6.0
- **Custom Classes**: Autoloaded via Composer PSR-4

### JavaScript
- **Direct File Inclusion** - No build system, direct file loading
- **jQuery Dependencies** - All custom scripts depend on jQuery
- **19 Specialized Files** - Modular architecture with specific functionality domains
- **FontAwesome** 6.5.1 via CDN
- **Dynamic Versioning** - `filemtime()` cache busting

## Database Tables

### Custom Tables
- `wp_user_session_tokens` - Cross-domain authentication
- `wp_band_subscribers` - Email consent management
- `wp_link_page_analytics` - Link click tracking

### Key Meta Fields
- `_band_profile_ids` - User to band associations
- `_link_page_custom_css_vars` - Link page customizations and styling
- `_bbp_forum_section` - Forum categorization (top/middle/bottom)
- `_band_subscribers` - Email subscriber consent tracking

## Current Status

The platform operates as a production WordPress theme serving the ExtraChill community. Core functionality includes band platforms, link page management, cross-domain authentication, and forum integration. Current technical debt includes 497 text domain references requiring migration and selective GeneratePress template dependencies.

## Cross-Domain Authentication Flow

1. User logs in on any domain via REST API
2. Session token generated in `wp_user_session_tokens`
3. Cookie set for `.extrachill.com` domain
4. Auto-login triggered on subsequent visits
5. External requests validated using Authorization header

This system enables seamless user experience across the entire ExtraChill ecosystem while maintaining security and performance.