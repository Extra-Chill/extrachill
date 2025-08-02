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

*Currently no known critical issues. Theme is production-ready and actively serving the ExtraChill community.*

**Legacy References**: Some files contain outdated `generatepress_child` text domain references that should be updated to `extra-chill-community` in future maintenance cycles.

## FUTURE PLANS

*Theme architecture is stable. Future development focused on feature enhancements and community growth tools.*

**Text Domain Cleanup**: Complete migration of all remaining `generatepress_child` text domain references to `extra-chill-community` across all theme files.

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

## Essential Commands

### Dependency Management
```bash
# Install PHP dependencies
composer install

# Install JavaScript dependencies (in /public directory)
cd /Users/chubes/Local\ Sites/community-stage/app/public
npm install

# Build assets (WordPress Scripts)
npx wp-scripts build

# Development with watch
npx wp-scripts start
```

### Important Development Notes
- **No webpack.config.js** - Uses WordPress Scripts defaults
- **No traditional test framework** - WordPress-based testing
- **Custom autoloading** - PSR-4 namespace: `Chubes\Extrachill\`
- **Asset Versioning** - Dynamic versioning using `filemtime()` for cache busting
- **Modular CSS/JS** - Conditional loading based on page templates and contexts

## Architecture Principles

### 1. Standalone Theme Structure
- **Theme Setup**: Full WordPress theme with `extra_chill_community_setup()` function
- **WordPress Features**: Supports automatic-feed-links, title-tag, post-thumbnails, custom-logo, HTML5 markup, customize-selective-refresh-widgets
- **Navigation Menus**: Primary ('primary') and Footer ('footer') menus registered with proper text domain
- **Widget Areas**: Custom sidebar ('sidebar-1') registration with proper escaping and structure
- **Asset Management**: Conditional CSS/JS loading with dynamic versioning using `filemtime()`
- **Template Hierarchy**: Includes required `index.php` template file as fallback
- **Code Organization**: Features organized in `band-platform/` directory with centralized includes
- **DRY Principle**: Consolidated forms and shared logic
- **WordPress Integration**: Extends existing WordPress/bbPress systems without parent theme dependencies

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
- `functions.php` - Main theme functions, WordPress feature support, and includes
- `index.php` - Main template file (required WordPress theme file)
- `style.css` - Main theme stylesheet with theme header information
- `bbpress-customization.php` - bbPress modifications

### Band Platform
- `band-platform/cpt-band-profile.php` - Band profile custom post type
- `band-platform/band-forums.php` - Automatic forum creation
- `band-platform/frontend-forms.php` - Band management forms
- `page-templates/manage-band-profile.php` - Band management interface

### Link Page System
- `band-platform/extrch.co-link-page/` - Complete link page system
- `single-band_link_page.php` - Public link page display
- `page-templates/manage-link-page.php` - Link page management
- `band-platform/extrch.co-link-page/link-page-form-handler.php` - Save processing

### Authentication
- `extrachill-integration/session-tokens.php` - Session management
- `extrachill-integration/validate-session.php` - Token validation
- `login/` - Login/registration system

### JavaScript Architecture
- **Main Manager**: `manage-link-page.js` - Central orchestrator
- **UI Modules**: Handle specific domains (styles, links, social icons)
- **Preview Engines**: Render live preview from DOM state
- **Save Handler**: `manage-link-page-save.js` - Serializes before submission

### Asset Enqueuing System
- **Main Stylesheet**: `extra-chill-community-style` - Primary theme styles enqueued via `extra_chill_community_enqueue_scripts()`
- **Modular CSS**: Context-specific loading (forums-loop, topics-loop, replies-loop, user-profile, notifications, leaderboard)
- **Page-Specific Assets**: Conditional loading for manage-band-profile, manage-link-page, settings-page, login-register
- **Component Styles**: Shared-tabs, band-switcher with dependency management
- **JavaScript Assets**: extrachill-utilities, extrachill-follow, custom-avatar, upvote, extrachill-mentions with conditional loading
- **External Dependencies**: FontAwesome 6.5.1 via CDN
- **Dynamic Versioning**: All assets use `filemtime()` for cache busting and fresh cache on updates
- **Script Dependencies**: Proper dependency management with jQuery and custom scripts
- **Asset Handles**: All handles prefixed with theme-specific naming (extrachill-, extra-chill-community-)

## Development Guidelines

### Theme Development Principles
1. **Standalone Architecture** - Complete WordPress theme with no parent theme dependencies
2. **WordPress Standards** - Full compliance with WordPress theme development guidelines and coding standards
3. **Theme Setup Hook** - Uses `after_setup_theme` action for proper theme initialization
4. **Modular Asset Loading** - Context-aware CSS/JS enqueuing for optimal performance
5. **Template Hierarchy** - Proper WordPress template structure with required `index.php` as fallback
6. **Widget System** - Custom widget area registration via `widgets_init` action
7. **Navigation System** - Registered navigation menus with proper escaping and text domain support

### Data Flow Principles
1. **PHP renders initial state** from database
2. **JavaScript listens for changes** and updates preview
3. **DOM serves as single source** of truth during editing
4. **Hidden inputs updated only before save** for PHP processing

### Code Patterns
- **Follow WordPress coding standards** and theme development best practices
- **Implement proper theme setup** with `after_setup_theme` hook and `extra_chill_community_setup()` function
- **WordPress Feature Support** - Proper `add_theme_support()` calls for core features
- **Navigation Menus** - `register_nav_menus()` with translatable labels and text domain
- **Widget Areas** - `register_sidebar()` with proper structure and escaping
- **Asset Enqueuing** - `wp_enqueue_style()` and `wp_enqueue_script()` with dependencies and versioning
- **Leverage bbPress hooks and filters** for forum integration
- **Maintain PSR-4 autoloading structure** via Composer
- **Use proper escaping** for all output (`esc_html()`, `esc_attr()`, `esc_url()`)
- **Implement nonce verification** for form processing and AJAX handlers
- **Conditional Loading** - Context-aware asset loading for performance optimization

### JavaScript Best Practices
- **No persistent JS state** - Always read from DOM
- **Module-based architecture** - Separate concerns clearly
- **AJAX only for** QR codes and analytics
- **Form submission for** all other data
- **Dynamic versioning** - Use `filemtime()` for script/style versions

## Dependencies

### PHP
- **WordPress** (with bbPress)
- **QR Code Generation**: `endroid/qr-code` ^6.0
- **Custom Classes**: Autoloaded via Composer PSR-4

### JavaScript
- **Webpack 5** for bundling
- **Babel** for transpilation
- **@wordpress/scripts** for WordPress tooling
- **FontAwesome** 6.5.1 via CDN

## Database Tables

### Custom Tables
- `wp_user_session_tokens` - Cross-domain authentication
- `wp_band_subscribers` - Email consent management
- `wp_link_page_analytics` - Link click tracking

### Key Meta Fields
- `_band_profile_ids` - User to band associations
- `_link_page_custom_css_vars` - Link page customizations
- `_bbp_forum_section` - Forum categorization

## Current Status

The platform is **feature-complete** with all core functionality implemented. Recent focus has been on bug fixes and performance optimizations. The codebase is production-ready and serves an active music community.

## Cross-Domain Authentication Flow

1. User logs in on any domain via REST API
2. Session token generated in `wp_user_session_tokens`
3. Cookie set for `.extrachill.com` domain
4. Auto-login triggered on subsequent visits
5. External requests validated using Authorization header

This system enables seamless user experience across the entire ExtraChill ecosystem while maintaining security and performance.