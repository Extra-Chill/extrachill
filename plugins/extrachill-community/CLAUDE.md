# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **WordPress Child Theme** for GeneratePress hosting the **ExtraChill** community platform - a comprehensive band platform and link page management system for musicians. The project serves a music community across multiple domains with seamless cross-domain authentication.

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

## Architecture Principles

### 1. Modular Structure
- **Code Location**: Features organized in `band-platform/` directory
- **DRY Principle**: Consolidated forms and shared logic
- **WordPress Integration**: Extends existing WordPress/bbPress systems

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
- `functions.php` - Main theme functions and includes
- `bbpress-customization.php` - bbPress modifications
- `style.css` - Child theme stylesheet

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

## Development Guidelines

### Data Flow Principles
1. **PHP renders initial state** from database
2. **JavaScript listens for changes** and updates preview
3. **DOM serves as single source** of truth during editing
4. **Hidden inputs updated only before save** for PHP processing

### Code Patterns
- **Follow WordPress coding standards**
- **Use existing GeneratePress patterns**
- **Leverage bbPress hooks and filters**
- **Maintain PSR-4 autoloading structure**

### JavaScript Best Practices
- **No persistent JS state** - Always read from DOM
- **Module-based architecture** - Separate concerns clearly
- **AJAX only for** QR codes and analytics
- **Form submission for** all other data

## Dependencies

### PHP
- **WordPress** (with bbPress)
- **QR Code Generation**: `endroid/qr-code` ^6.0
- **Custom Classes**: Autoloaded via Composer

### JavaScript
- **Webpack 5** for bundling
- **Babel** for transpilation
- **@wordpress/scripts** for WordPress tooling

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