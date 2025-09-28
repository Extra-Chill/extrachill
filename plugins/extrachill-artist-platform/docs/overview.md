# Extra Chill Artist Platform

Comprehensive WordPress plugin providing complete artist platform functionality for the Extra Chill community. Enables artists to create profiles, link pages, manage subscribers, and integrate with forums.

## System Architecture

The platform consists of two primary custom post types:
- **artist_profile**: Artist/band profiles with archive at `/artists/` and individual pages at `/artists/{slug}`
- **artist_link_page**: Link pages with top-level slug structure at `/{slug}`

## Core Components

### Custom Post Types
- Artist profiles with comprehensive metadata support
- Link pages with drag-and-drop link management
- Built-in thumbnail support and custom fields

### Management Systems
- **Live Preview**: Real-time preview system for link page customization
- **Roster Management**: Band member invitation and role assignment system
- **Social Integration**: 15+ social platform support with comprehensive validation
- **Analytics**: Daily aggregated page views and link click tracking
- **Subscription System**: Email collection with artist association

### Advanced Features
- **Cross-Domain Authentication**: WordPress multisite native authentication across `.extrachill.com` subdomains (legacy session tokens maintained for compatibility)
- **Link Expiration**: Time-based link scheduling and lifecycle management
- **Forum Integration**: bbPress integration with artist-specific forum sections
- **Asset Management**: Context-aware loading with file existence checks

## Data Architecture

The system uses a centralized data approach with `ec_get_link_page_data()` as the single source of truth for all link page configuration, CSS variables, links, and social data. This replaces scattered `get_post_meta()` calls and provides consistent data access across templates, AJAX handlers, and asset management.

## Template System

Dual template architecture:
1. **Page Templates**: Full page routing via `ExtraChillArtistPlatform_PageTemplates` class
2. **Component Templates**: Modular UI components via `ec_render_template()` system

## JavaScript Architecture

Event-driven modular system using IIFE patterns with standardized CustomEvent communication between management and preview modules. Features responsive tab interfaces, drag-and-drop link reordering, and native Web Share API integration.

## Dependencies

- WordPress 5.0+ (tested up to 6.4)
- PHP 7.4+
- Extrachill theme with Extra Chill Community plugin (enforced)
- bbPress plugin for forum integration