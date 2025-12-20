# AGENTS.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **WordPress plugin** called "Extra Chill Community" for the **Extra Chill** community platform - a music community with comprehensive forum enhancements and cross-domain authentication. The plugin provides community and forum functionality that integrates with the extrachill theme.

This plugin is part of the Extra Chill Platform, a WordPress multisite network serving music communities across 9 active sites.

**Plugin Information:**
- **Name**: Extra Chill Community
- **Version**: 1.1.4
- **Text Domain**: `extra-chill-community`
- **Author**: Chris Huber
- **Author URI**: https://chubes.net
- **License**: GPL v2 or later
- **License URI**: https://www.gnu.org/licenses/gpl-2.0.html
- **Requires at least**: 5.0
- **Tested up to**: 6.4

## KNOWN ISSUES

**PSR-4 Implementation**: No PSR-4 autoloading configured in composer.json. The plugin uses procedural patterns with direct `require_once` loading.

**File Migration**: Avatar functionality (custom-avatar.php, upload-custom-avatar.php, custom-avatar.js, online-users-count.php) moved to extrachill-users plugin for network-wide availability. Utilities.js deleted from codebase.

## Key Domains & Architecture

- `community.extrachill.com` - Main platform (WordPress/bbPress) **[Uses extrachill theme + this plugin]**
- `extrachill.com` - Main website **[Uses extrachill theme + cross-domain integration]**

## Core Features

1. **Forum Features** - Comprehensive bbPress extensions with organized feature architecture
2. **Cross-Domain Authentication** - WordPress multisite native authentication system for seamless cross-domain user sessions  
3. **Social Features** - User interactions, following system, upvoting, notifications, and rank system
4. **User Management** - Custom profiles, settings, email verification, and notification system
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

### Automatic Setup on Activation
When the plugin is activated, it automatically creates required pages and forums:

**Pages Created** (5 total):
- Settings page (`/settings`) - Account management, email, password, subscriptions
- Notifications page (`/notifications`) - User notification display
- Recent Activity page (`/recent`) - Community activity feed (uses "Recent Activity Feed" template)
- Leaderboard page (`/leaderboard`) - User rankings (uses "Leaderboard" template)
- Blog Comments page (`/blog-comments`) - Main blog comments (uses "Main Blog Comments Feed" template)

**Forums Created** (2 total):
- Local Scenes (`/r/local-scenes`) - Discuss local music scenes
- Music Discussion (`/r/music-discussion`) - General music discussion

**Activation Logic** (`inc/core/activation.php`):
- Registered via `register_activation_hook()` in main plugin file
- Checks if bbPress is active before proceeding
- Skips page/forum creation if slug already exists (prevents duplicates)
- Assigns page templates automatically
- No deletion on deactivation - pages remain for user content

### Development Notes
- **No Asset Compilation** - Direct file inclusion without npm/webpack compilation
- **bbPress Integration** - Default stylesheet dequeuing, custom templates, enhanced functionality

### Build System
- **Universal Build Script**: Symlinked to shared build script at `../../.github/build.sh`
- **Auto-Detection**: Script auto-detects plugin from `Plugin Name:` header
- **Production Build**: Creates `/build/extrachill-community/` directory and `/build/extrachill-community.zip` file (non-versioned)
- **No Asset Compilation Required**: Plugin uses direct file inclusion (run `./build.sh` directly)
- **File Exclusion**: `.buildignore` rsync patterns exclude development files
- **Composer Integration**: Uses `composer install --no-dev` for production, restores dev dependencies after

## Branch Strategy

### Main Branch
The `main` branch contains stable, production-ready code. All production builds should be created from this branch.

### Blocks Everywhere Integration

**PRODUCTION-READY**: The Blocks Everywhere integration is fully implemented and active on community.extrachill.com as of version 1.0.17.

**Integration Details**:
- File: `inc/content/editor/blocks-everywhere.php` - Filter-based integration with Blocks Everywhere plugin
- Enables Gutenberg block editor for both frontend users and admin editing of bbPress content
- Automatically disables TinyMCE when Blocks Everywhere is active
- Conditional asset loading for iframe editor assets

**Allowed Block Types**:
- `core/paragraph` - Default text content
- `core/heading` - Headings for content structure
- `core/embed` - Media embeds (YouTube, SoundCloud, etc.)
- **Disabled**: `core/code` block for security reasons

**Technical Implementation**:
- Uses `blocks_everywhere_bbpress` and `blocks_everywhere_bbpress_admin` filters to enable functionality
- Filter-based block type restrictions via `blocks_everywhere_allowed_blocks` filter
- Seamless integration with existing bbPress permissions and security
- Compatible with email notifications (blocks converted to plain text)

## Architecture Principles

### 1. Plugin Architecture
- **Plugin Structure**: WordPress plugin providing community functionality that integrates with the extrachill theme
- **bbPress Integration**: Custom bbPress enhancements and forum functionality
- **Asset Management**: Conditional CSS/JS loading with cache-busting versioning
- **Template System**: Provides custom bbPress templates and specialized page templates
- **Hook-Based Components**: Homepage and settings use action hooks instead of monolithic templates

### 2. Cross-Domain Session Management
- **WordPress Multisite**: Native WordPress multisite provides unified authentication across all Extra Chill domains
- **Cookie Domain**: WordPress multisite handles cross-domain authentication via `.extrachill.com` subdomain coverage

## Critical File Locations

### Core Plugin Files
- `extrachill-community.php` - Main plugin file with explicit `require_once` statements in `extrachill_community_init()`
- `inc/core/assets.php` - Asset management and enqueuing system
- `inc/core/bbpress-templates.php` - bbPress template routing system
- `inc/core/breadcrumb-filter.php` - bbPress breadcrumb customization
- `inc/core/page-templates.php` - Page template routing
- `inc/core/bbpress-spam-adjustments.php` - bbPress spam adjustments
- `inc/core/sidebar.php` - Sidebar functionality
- `inc/core/filter-bar.php` - Forum filter bar integration with theme's universal filter bar component

### Forum Features System (inc/ structure)

**Explicit Loading Pattern** - All files loaded via direct `require_once` in `extrachill_community_init()`:

**Core (9 files)**:
- `inc/core/assets.php`, `bbpress-templates.php`, `breadcrumb-filter.php`, `page-templates.php`, `bbpress-spam-adjustments.php`, `sidebar.php`, `nav.php`, `cache-invalidation.php`, `filter-bar.php`

**Content (6 files)**:
- `inc/content/editor/tinymce-customization.php`, `editor/tinymce-image-uploads.php`
- `inc/content/content-filters.php`, `recent-feed.php`, `main-site-comments.php`, `subforum-button-classes.php`

**Social (11 files)**:
- `inc/social/upvote.php`, `forum-badges.php`
- `inc/social/rank-system/point-calculation.php`, `rank-system/chill-forums-rank.php`
- `inc/social/notifications/notification-bell.php`, `notification-card.php`, `notification-handler.php`
- `inc/social/notifications/notification-cleanup.php`, `capture-replies.php`, `capture-mentions.php`, `notifications-content.php`

**User Profiles (7 files)**:
- `inc/user-profiles/custom-user-profile.php`, `verification.php`
- `inc/user-profiles/settings/settings-content.php`, `settings/settings-form-handler.php`
- `inc/user-profiles/edit/user-links.php`, `edit/user-info.php`, `edit/avatar-upload.php`

**Home (5 files)**:
- `inc/home/latest-post.php`, `actions.php`, `homepage-forum-display.php`, `artist-platform-buttons.php`, `new-topic-modal.php`

**Total: 36 files loaded in init function**

**Deleted file**: `inc/social/user-mention-api.php` - Moved to extrachill-api plugin and stub removed

**Moved to extrachill-users plugin**: Avatar display logic (avatar-display.php), online-users-count.php, user-avatar-menu.php

**Avatar Upload UI**: `inc/user-profiles/edit/avatar-upload.php` and `inc/assets/js/avatar-upload.js` provide the bbPress profile edit integration for avatar uploads. Uses centralized REST API (`/wp-json/extrachill/v1/media`) from extrachill-api plugin.

**Deleted files**: inc/core/nav.php, inc/assets/js/utilities.js

### Integration Files

**bbPress Integration**:
- `inc/core/bbpress-templates.php` - Template stack registration and homepage override
- `inc/core/breadcrumb-filter.php` - bbPress breadcrumb customization
- `inc/core/assets.php` - bbPress context detection and stylesheet dequeue

**Artist Platform Integration**:
- `inc/home/artist-platform-buttons.php` - Artist platform buttons and CTAs
- `inc/user-profiles/verification.php` - Artist/professional status (admin-only)
- `inc/social/forum-badges.php` - Artist badge display in forums

**Users Plugin Integration**:
- `inc/social/forum-badges.php` - Team member badge using `ec_is_team_member()`
- `inc/user-profiles/custom-user-profile.php` - Cross-site user data aggregation
- `inc/social/rank-system/point-calculation.php` - Cross-site point calculation

### Page Templates
- `page-templates/leaderboard-template.php` - User leaderboard
- `page-templates/main-blog-comments-feed.php` - Cross-domain blog comments
- `page-templates/recent-feed-template.php` - Recent community activity

### Settings System (Hook-Based)
- `inc/user-profiles/settings/settings-content.php` - Settings page content rendering via hook
- `inc/user-profiles/settings/settings-form-handler.php` - Form processing and validation

### JavaScript Architecture (8 files in inc/assets/js/)

**Loaded via assets.php (4 files)**:
- `upvote.js` - Content upvoting system (bbPress and recent page)
- `extrachill-mentions.js` - User mention system with reply button handler (bbPress only)
- `bbpress-ui.js` - Forum UI interactions: jump-to-latest, sort auto-submit, TinyMCE autosave (bbPress only)
- `content-expand.js` - Content expansion functionality (recent page, blog comments feed)

**Loaded independently by feature modules (4 files)**:
- `manage-user-profile-links.js` - Profile links editor (loaded by `inc/user-profiles/edit/user-links.php`)
- `avatar-upload.js` - Avatar upload via REST API (loaded by `inc/user-profiles/edit/avatar-upload.php`)
- `tinymce-image-upload.js` - TinyMCE image upload plugin (loaded via `mce_external_plugins` filter in `inc/content/editor/tinymce-image-uploads.php`)
- `new-topic-modal.js` - New topic modal trigger and close handlers (loaded by `inc/core/assets.php` on front page)

**Removed files**: utilities.js (deleted)

### CSS Files (11 files in inc/assets/css/)
- bbpress.css, blog-comments-feed.css, global.css, home.css, leaderboard.css
- notifications.css, replies-loop.css, settings-page.css, tinymce-editor.css
- topics-loop.css, user-profile.css

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
5. **bbPress Enhancement** - Extends bbPress functionality with custom features
6. **Cross-Domain Integration** - Provides multisite authentication and data sharing
7. **Performance Optimization** - Conditional loading and selective script enqueuing

### Forum Features Architecture
1. **Organized Structure** - Features grouped by functionality: core (8), content (6), social (11), user-profiles (7), home (4)
2. **Conditional Loading** - Context-aware CSS/JS loading for performance
3. **bbPress Integration** - Custom templates via `inc/core/bbpress-templates.php` routing, breadcrumb customization via `bbp_breadcrumbs` filter
4. **Hook-Based Components** - Homepage and settings use action hooks for extensibility

### Code Patterns
- **WordPress Coding Standards** - Full compliance with plugin development best practices
- **bbPress Enhancement** - Custom hooks, filters, and functionality extensions
- **Security Implementation** - Proper escaping, nonce verification, and input sanitization
- **Performance Focus** - Modular CSS/JS loading and conditional script enqueuing
- **Cross-Domain Functionality** - Multisite authentication and data sharing capabilities

### JavaScript Architecture Principles
- **Modular Design** - 6 JS files in `inc/assets/js/` with specialized functionality domains
- **Mixed Loading** - 3 files via assets.php centrally, 2 loaded independently by feature modules
- **Separation of Concerns** - Reply button handler separated from TinyMCE plugin guard for better reliability
- **jQuery Dependencies** - Proper dependency management across all custom scripts
- **Context-Aware Loading** - Conditional script enqueuing based on page template/context
- **Cache Busting** - Automatic versioning for asset updates
- **Forum Integration** - Custom bbPress enhancements for editor, social features, and UI

## Dependencies

### PHP
- **WordPress** 5.0+ (with bbPress required)
- **Composer Dependencies**: None (minimal composer.json structure only)

### JavaScript
- **Direct File Inclusion** - No build system, direct file loading
- **jQuery Dependencies** - All custom scripts depend on jQuery
- **8 Files Total** - 4 via assets.php centrally, 4 via feature modules independently
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

The `ec_avatar_menu_items` filter (provided by extrachill-users plugin) allows plugins to add custom menu items to the user avatar dropdown menu in the header.

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

**Note**: This filter is PROVIDED by the extrachill-users plugin, not this plugin. Community plugin can use this filter to add menu items.

## Plugin Integration Points

### bbPress Integration

The plugin provides comprehensive bbPress enhancements through multiple integration layers:

**Template System Integration**:
- File: `inc/core/bbpress-templates.php`
- Hook: `bbp_register_theme_packages` registers custom template stack
- Custom templates location: `bbpress/` directory (70+ template files)
- Template discovery: `bbp_register_template_stack()` enables bbPress to find plugin templates
- Homepage override: Blog ID 2 (community.extrachill.com) only via `extrachill_template_homepage` filter
- Statistics suppression: `bbp_get_single_forum_description` filter returns empty string

**Asset Loading Integration**:
- File: `inc/core/assets.php`
- Context detection using bbPress conditionals: `bbp_is_forum_archive()`, `bbp_is_single_forum()`, `bbp_is_topic_archive()`, `bbp_is_single_topic()`, `bbp_is_single_reply()`, `bbp_is_single_user()`, etc.
- Default stylesheet dequeue: `wp_dequeue_style('bbp-default')` at priority 15 to prevent conflicts

**Hook Integration Points**:
- `bbp_theme_after_reply_author_details` - Adds user badges after reply author
- `bbp_theme_after_user_name` - Adds badges after username
- `bbp_template_after_user_details_menu_items` - Adds badges in user details menu
- `bbp_breadcrumbs` - Customizes forum breadcrumb navigation (file: `inc/core/breadcrumb-filter.php`)

**Form and Content Integration**:
- Custom form templates with TinyMCE rich text editor
- Image upload support via custom plugin
- Point calculation using `bbp_get_user_topic_count()` and `bbp_get_user_reply_count()`

### extrachill-artist-platform Integration

**Direct Integration**:
- File: `inc/home/artist-platform-buttons.php`
- Hook: `extrachill_community_home_after_forums` (line 50) adds artist platform CTAs to homepage

**Function Dependencies**:
```php
// Provided by extrachill-artist-platform plugin
ec_can_create_artist_profiles($user_id) // Returns boolean for artist creation permission
```

**Hardcoded Links**:
- Artist platform homepage: `https://artist.extrachill.com/`
- Support forum: `https://artist.extrachill.com/extra-chill`
- Join flow: `https://artist.extrachill.com/login/#tab-register?from_join=true`

**Artist Status Storage**:
- Meta field: `user_is_artist` (Boolean stored as '1' or '0')
- File: `inc/user-profiles/verification.php` (admin-only interface)
- Badge display: `inc/social/forum-badges.php` (CSS class: `user-is-artist`)

**Data Flow**:
- Community plugin stores artist status flag locally
- Artist platform provides permission check function
- No direct database queries to artist platform tables
- Uses user metadata for badge display

### extrachill-users Integration

**Team Member Detection**:
- File: `inc/social/forum-badges.php` (lines 18-19, 39-40, 63-64)
- Function: `ec_is_team_member($user_id)` - Provided by extrachill-users plugin
- Returns boolean for team member status (supports manual admin overrides)
- Badge display: `<span class="extrachill-team-member">`
- Displayed in 3 locations: reply author details, username, user details menu

**User Role Fields**:
- File: `inc/user-profiles/verification.php`
- Hooks: `show_user_profile`, `edit_user_profile` (admin-only interface)
- Admin-only restriction prevents frontend data conflicts
- Meta fields: `user_is_artist`, `user_is_professional`, `ec_custom_title`

**Avatar Menu System**:
- Filter: `ec_avatar_menu_items` (provided by extrachill-users plugin)
- Community plugin documents this filter for use by other plugins
- Not actively used internally by community plugin
- Allows cross-plugin navigation integration

**Cross-Site User Data**:
- File: `inc/user-profiles/custom-user-profile.php`
- Uses `switch_to_blog(1)` to access main site (extrachill.com) data
- Aggregates user post count from blog ID 1
- Aggregates user comments from blog ID 1
- Always uses `restore_current_blog()` in try/finally pattern

**User Profile Data**:
- Social links meta: `_user_profile_dynamic_links`
- Custom user titles: `ec_custom_title`
- Notification cache: `extrachill_notifications`

## Current Status

The plugin operates as a production WordPress plugin serving the Extra Chill community alongside the extrachill theme. Core functionality includes forum enhancements, WordPress multisite authentication, and bbPress integration. The plugin provides community functionality for community.extrachill.com while the extrachill theme handles the visual presentation.

**Migration Complete**: The community functionality has been successfully transitioned from a standalone theme to a plugin-based architecture. All theme files (header.php, footer.php, index.php, functions.php) have been removed. The plugin now provides clean forum functionality that integrates with the extrachill theme.

**Modern Architecture**: The plugin uses hook-based components for homepage and settings pages, organized file structure in `inc/` directory, and WordPress multisite native authentication. All assets moved to `inc/assets/css/` and `inc/assets/js/` directories.

**Plugin Integration**: Other plugins can use the `ec_avatar_menu_items` filter (provided by extrachill-users plugin) to add custom menu items to the user avatar dropdown, maintaining seamless navigation between community and plugin-specific functions.

## Cross-Domain Authentication Flow

### WordPress Multisite Native Authentication
1. User logs in on any Extra Chill domain
2. WordPress multisite automatically provides authentication across all `.extrachill.com` subdomains
3. Native WordPress user sessions handle cross-domain authentication
4. Users remain logged in across all Extra Chill properties without additional validation

**Migration Complete**: The plugin now uses WordPress multisite native authentication exclusively. All custom session token functionality has been removed. Cross-domain integration relies entirely on WordPress core multisite capabilities for authentication.