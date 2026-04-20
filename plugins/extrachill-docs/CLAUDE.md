# Extra Chill Docs - Agent Development Guide

## Plugin Information
- **Version**: 0.4.0
- **Network**: false (site-activated on docs.extrachill.com, Blog ID 10)

## Overview
User-facing documentation platform for the Extra Chill network. Deployed on docs.extrachill.com (Blog ID 10). Uses `ec_doc` custom post type with `ec_doc_platform` taxonomy for clean `/platform-slug/doc-slug/` URLs. Homepage displays dynamic platform cards for documentation navigation.

The public `GET /wp-json/extrachill/v1/docs-info` endpoint returns docs metadata plus the main-site About page content (blog 1, slug `about`) and only taxonomy terms that have published posts, with counts per term.

## Architecture

### Custom Post Type
- `ec_doc` - Documentation articles with hierarchical support (like pages)

### Custom Taxonomy
- `ec_doc_platform` - Organizes docs by network site (hierarchical, category-like)

### URL Structure
```
docs.extrachill.com/                              → Homepage with platform cards
docs.extrachill.com/artist/                     → Platform archive (all artist docs)
docs.extrachill.com/doc/create-link-page/         → Individual doc article
```

### Why Custom Post Type + Taxonomy
- **Isolation**: No inheritance from core posts/categories
- **Clean Admin**: Dedicated "Documentation" menu with "Platforms" submenu
- **No Conflicts**: Zero interference if blog posts are ever added
- **Future-Proof**: Complete separation from WordPress core content types

## File Structure
```
extrachill-docs/
├── extrachill-docs.php           # Main plugin, activation hook
├── homeboy.json                  # Homeboy build/deploy config
├── inc/core/
│   ├── post-types.php            # ec_doc registration
│   ├── register-platform-taxonomy.php  # ec_doc_platform registration
│   ├── taxonomy-seed.php         # Platform seeding from ec_get_blog_ids()
│   ├── assets.php                # CSS/JS loading
│   ├── homepage.php              # Platform cards
│   ├── breadcrumbs.php           # Breadcrumb + schema integration
│   ├── sidebar.php               # TOC sidebar generation
│   ├── filters.php               # Theme integration filters
│   └── rewrite-rules.php         # Custom URL rewriting
├── assets/
│   ├── css/docs.css              # All docs styles
│   └── js/docs-toc.js            # TOC scroll tracking
├── ec_docs/                      # Markdown source of truth
│   ├── artist/                   # → artist.extrachill.com
│   ├── community/                # → community.extrachill.com
│   └── events/                   # → events.extrachill.com
├── scripts/                      # Manual sync tools (legacy, pending DM pipeline)
│   ├── sync.sh
│   ├── upload.php
│   ├── WordPressClient.php
│   └── FileFinder.php
└── docs/
    └── CHANGELOG.md
```

## Default Platforms

Derived from `ec_get_blog_ids()` in `extrachill-multisite` (canonical source of truth). Excludes `docs` (self).
- artist → Artist Platform
- community → Community
- events → Events
- shop → Shop
- main → Blog
- newsletter → Newsletter
- wire → News Wire
- studio → Studio

Platforms without published documentation are not displayed on the homepage.

## Theme Integration
- Hooks into `extrachill_homepage_content` action for homepage rendering
- Uses theme CSS variables from root.css (--card-background, --accent, --spacing-*, etc.)
- Theme handles ec_doc archive and single templates automatically
- Breadcrumb integration provides "Extra Chill → Documentation" navigation with network dropdown

## Content Guidelines
- Write for non-technical users
- Include screenshots and visual walkthroughs
- Use step-by-step formatting
- Link to relevant platform sections

## Dependencies
- extrachill theme (CSS variables)
- extrachill-multisite (canonical site map for platform seeding)
- extrachill-api (`/wp-json/extrachill/v1/sync/doc` endpoint for markdown sync)

## Build & Deploy
- **Homeboy** manages build and deploy: `homeboy build extrachill-docs && homeboy deploy`
- No `build.sh` — homeboy standardizes the build process
- No frontend build step (no webpack/vite — CSS and vanilla JS only)

## Content Sync
The `scripts/` directory contains a manual sync pipeline that pushes `ec_docs/*.md` to the `/sync/doc` REST endpoint. This is the current mechanism; the DM pipeline (fetch → AI → upsert to GitHub + publish to WordPress) will eventually replace it.
