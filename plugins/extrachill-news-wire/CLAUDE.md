# Extra Chill News Wire - Agent Development Guide

## Overview
News wire hub plugin for wire.extrachill.com (Blog ID 11). Quarantines automated news feeds from the main editorial site while providing dedicated wire functionality with custom post types and filtering.

## Custom Post Types

### Festival Wire (`festival_wire`)
- Dedicated post type for festival news and coverage
- Full WordPress editor support with title, content, excerpt, thumbnail
- Archive pages at `/festival-wire/` with filtering and pagination
- Single post templates for individual festival wire posts
- REST API integration for potential mobile consumption

## Wire Site Integration (Blog ID 11)
- Activated exclusively on wire.extrachill.com
- Homepage serves as hub gateway to multiple wire feeds (Festival Wire is first)
- Uses theme's `extrachill_pagination()` function for archive pages
- Assets loaded conditionally on relevant pages

## Event Coverage Features
- Festival taxonomy for primary classification
- Data source taxonomy for content attribution
- Tag migration tools for converting post tags to festival taxonomy
- Author migration for bulk reassignment

## File Organization
```
extrachill-news-wire/
├── extrachill-news-wire.php     # Main plugin file
├── includes/                    # Core functionality
│   ├── festival-wire-post-type.php       # Custom post type registration
│   ├── festival-wire-query-filters.php   # Query modifications
│   ├── festival-metadata.php             # Festival metadata handling
│   ├── theme-integration.php             # Theme integration hooks
│   └── core/
│       ├── breadcrumbs.php               # Breadcrumb integration
│       ├── festival-hub-header.php       # Festival hub header
│       ├── festival-term-meta.php        # Festival term meta
│       └── post-meta.php                 # Post meta handling
├── templates/                   # Template files
│   ├── archive-festival_wire.php         # Archive template
│   ├── single-festival_wire.php          # Single post template
│   ├── home-wire.php                     # Wire hub homepage
│   └── content-card.php                  # Content card component
├── assets/                      # CSS/JS assets
│   ├── festival-wire.css        # Plugin styles
│   └── festival-wire.js         # Filter and FAQ accordion functionality
└── docs/                        # Documentation
    ├── overview.md              # Architecture and component overview
    └── CHANGELOG.md             # Version history
```

## Theme Integration (`includes/theme-integration.php`)

Hooks into Extra Chill theme filters to register festival_wire post type for theme assets and taxonomies. Keeps all EC-specific logic in the plugin while allowing theme to remain generic.

### Taxonomy Registration
- Registers `festival` and `location` taxonomies for `festival_wire` post type
- Theme registers these taxonomies for `post` only; plugin extends support via `register_taxonomy_for_object_type()`
- Hook: `init` action at priority 20 (after theme taxonomy registration)

### Theme Asset Filters
**Single Post Styles** (`extrachill_single_post_style_post_types` filter):
- Adds `festival_wire` to post types that load `single-post.css`
- Ensures consistent styling for festival wire single posts

**Sidebar Styles** (`extrachill_sidebar_style_post_types` filter):
- Adds `festival_wire` to post types that load `sidebar.css`
- Enables sidebar support for festival wire singles

### Custom Sidebar Content
**Recent Posts Override** (`extrachill_sidebar_recent_posts_content` filter):
- Provides custom "Latest Festival Wire" sidebar content for festival_wire singles
- Displays 3 most recent festival_wire posts (excluding current)
- Uses mini-card format with thumbnails and titles
- Returns false for non-festival_wire contexts (allows default behavior)

## Dependencies
- WordPress 5.0+
- PHP 7.4+
- extrachill theme (for template integration and taxonomy definitions)