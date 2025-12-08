# Extra Chill Docs - Agent Development Guide

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
docs.extrachill.com/artist-platform/              → Platform archive (all artist docs)
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
├── inc/
│   ├── core/
│   │   ├── post-types.php        # ec_doc registration
│   │   ├── taxonomies.php        # ec_doc_platform registration + seeding
│   │   └── assets.php            # CSS loading with filemtime()
│   └── home/
│       └── homepage-cards.php    # Platform cards via extrachill_homepage_content
└── assets/
    └── css/
        └── docs.css              # Card grid styling
```

## Default Platforms
Seeded on activation (all planned network platforms):
- Extra Chill Artist Platform
- Extra Chill Community
- Extra Chill Events
- Extra Chill Stream
- Extra Chill Newsletter
- Extra Chill Shop
- Extra Chill Chat
- Extra Chill Horoscopes

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
- No cross-plugin dependencies (standalone)

## Future Considerations
- AI-assisted content generation from plugin AGENTS.md files
- Sidebar navigation showing sibling docs in same platform
- "Was this helpful?" feedback widget
- Integration with extrachill-search for network-wide discoverability
