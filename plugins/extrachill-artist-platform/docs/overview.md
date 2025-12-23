# Extra Chill Artist Platform - Technical Documentation

This directory contains technical deep-dive documentation for the Artist Platform plugin.

**For architectural patterns and development guidelines**, see [../AGENTS.md](../AGENTS.md)
**For quick overview and installation**, see [../README.md](../README.md)

---

Comprehensive WordPress plugin providing complete artist platform functionality for the Extra Chill community. Enables artists to create profiles, link pages, and manage subscribers.

## System Architecture

The platform consists of two primary custom post types:
- **artist_profile**: Artist/band profiles with archive at `/artists/` and individual pages at `/artists/{slug}`
- **artist_link_page**: Link pages with top-level slug structure at `/{slug}` (accessible at `extrachill.link/{artist-slug}`)

## Core Components

### Custom Post Types
- Artist profiles with comprehensive metadata support
- Link pages with drag-and-drop link management via Gutenberg blocks
- Built-in thumbnail support and custom fields

### Management Systems
- **Join Flow**: Login/register UX integration via `from_join` parameter with post-auth routing
- **Link Page Editor Block**: Gutenberg block editor (React-based) for link page management
- **Artist Profile Manager Block**: Gutenberg block editor (React-based) for artist profile management
- **Link Page Analytics Block**: Dedicated analytics dashboard via Gutenberg block
- **Artist Creator Block**: Guided artist profile creation flow
- **Artist Shop Manager Block**: Shop product management interface
- **Roster Management**: Band member invitation and role assignment system
- **Social Integration**: 15+ social platform support with comprehensive validation
- **Analytics**: Daily aggregated page views and link click tracking with REST API access
- **Subscription System**: Email collection with artist association

### Advanced Features
- **Five Gutenberg Blocks**: Modern React-based blocks for complete platform management
  - `link-page-editor`: Full link page editing with all features
  - `artist-analytics`: Analytics dashboard with Chart.js visualization
  - `artist-manager`: Artist profile management and metadata
  - `artist-creator`: Artist profile creation
  - `artist-shop-manager`: Shop product management, orders, and Stripe Connect integration
- **Cross-Domain Authentication**: WordPress multisite authentication across `.extrachill.com` subdomains and `extrachill.link`
- **Link Expiration**: Time-based link scheduling and lifecycle management
- **Asset Management**: Context-aware loading with file existence checks and timestamp cache busting
- **Webpack Build System**: Automated React component and SCSS compilation for Gutenberg blocks

## Data Architecture

The system uses a centralized data approach with multiple functions as single sources of truth:
- **`ec_get_link_page_data()`**: Comprehensive link page configuration, CSS variables, links, and social data
- **`ec_get_artist_profile_data()`**: Complete artist profile metadata and settings
- These replace scattered `get_post_meta()` calls and provide consistent data access across templates, blocks, and API endpoints

## Template System

Dual template architecture:
1. **Page Templates**: Full page routing via `ExtraChillArtistPlatform_PageTemplates` class
2. **Component Templates**: Modular UI components via `ec_render_template()` system

## Gutenberg Block System

**Location**: `src/blocks/`

Five React-based Gutenberg blocks providing complete platform management:
- **link-page-editor**: Link page editing interface
  - Includes TabInfo, TabLinks, TabCustomize, TabAdvanced, TabSocials
  - Live preview with Context API state management
  - REST API integration for all data operations

- **artist-analytics**: Dedicated analytics dashboard
  - Chart.js-powered visualization
  - Daily page views and link click tracking

- **artist-manager**: Artist profile management
  - Profile editing, roster management, subscribers

- **artist-creator**: Artist profile creation flow

- **artist-shop-manager**: Shop product management
  - Product CRUD operations with image uploads
  - Order management with shipping label integration
  - Stripe Connect payment processing
  - Inventory tracking with size variants

**Build Process**:
```bash
npm run build   # Production build
npm run start   # Development with watch mode
```

Compiled assets available at `build/blocks/` for automatic block registration.

## JavaScript Architecture

Gutenberg block-based system using React components. Modern JavaScript patterns with REST API integration for data operations. Features responsive tab interfaces, drag-and-drop link reordering via dnd-kit, native Web Share API integration, and touch-friendly mobile support.

## Dependencies

- WordPress 5.0+ (tested up to 6.4)
- PHP 7.4+
- Required Plugin: extrachill-users (for artist profile functions)
- Extrachill theme (enforced for proper styling)