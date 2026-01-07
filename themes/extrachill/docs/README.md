# ExtraChill Theme - Technical Documentation

This directory contains technical deep-dive documentation for the ExtraChill theme.

**For architectural patterns and development guidelines**, see ../AGENTS.md
**For quick overview and installation**, see ../README.md

---

Technical documentation for developers working on the ExtraChill WordPress theme.

## Overview

ExtraChill is a custom WordPress theme serving a music community ecosystem across a multisite network. The theme powers the active sites (Blog IDs 1–5, 7–12) in the Extra Chill Platform network, with docs.extrachill.com at Blog ID 10, wire.extrachill.com at Blog ID 11, and horoscope.extrachill.com at Blog ID 12.

**Version**: 1.3.12
**Author**: Chubes
**Text Domain**: extrachill

## Theme Architecture

### Core Principles

- **WordPress Native Routing**: Uses `template_include` filter for proper WordPress integration with custom page template support
- **Hook-Based Extensibility**: Action and filter hooks throughout for plugin integration
- **Modular Organization**: Modular PHP files under `/inc` loaded via direct `require_once` includes in `functions.php`
- **Performance Focused**: Conditional asset loading, hardcoded menus, `filemtime()` cache busting
- **Multisite Integration**: Integration with the WordPress multisite network (site navigation dropdown, cross-site URL helpers)

### File Structure

```
extrachill/
├── functions.php                 # Theme setup and configuration
├── index.php                     # Emergency fallback template
├── style.css                     # Main stylesheet
├── inc/                          # Modular functionality
│   ├── core/                     # Core theme features
│   │   ├── template-router.php  # WordPress native template routing
│   │   ├── actions.php          # Action hook registration
│   │   ├── assets.php           # Asset loading system
│   │   ├── custom-taxonomies.php # Custom taxonomy registration
│   │   ├── view-counts.php      # View-count display helpers (reads `ec_post_views` meta)
│   │   ├── rewrite.php          # URL rewrite rules (category base removal)
│   │   ├── notices.php          # Notice system
│   │   ├── templates/           # Shared template components
│   │   └── editor/              # Custom embeds (Bandcamp, Instagram)
│   ├── header/                   # Navigation system
│   ├── footer/                   # Footer menus
│   ├── home/                     # Homepage components
│   ├── single/                   # Single post/page templates
│   ├── archives/                 # Archive functionality
│   │   └── search/              # (legacy; header/search templates live under core/templates)
│   └── sidebar/                  # Sidebar widgets
└── assets/                       # CSS, JavaScript, fonts
    ├── css/                      # 11 modular CSS files
    ├── js/                       # JavaScript functionality
    └── fonts/                    # FontAwesome SVG sprite with QR/download icons
```

## Developer Quick Reference

### Essential Hooks

**Template Override**:
```php
add_filter( 'extrachill_template_archive', function( $template ) {
    return MY_PLUGIN_DIR . '/custom-archive.php';
} );
```

**Add Navigation Item**:
```php
add_action( 'extrachill_header_top_right', function() {
    echo '<a class="header-right-icon" href="/custom">Custom</a>';
}, 20 );
```

**Customize Post Meta**:
```php
add_filter( 'extrachill_post_meta', function( $meta, $post_id, $post_type ) {
    return $meta . '<div>Custom metadata</div>';
}, 10, 3 );
```

### Essential Functions

**Display Post Views**:
```php
if ( function_exists( 'ec_the_post_views' ) ) {
	ec_the_post_views();
}
```

**Display Taxonomy Badges**:
```php
extrachill_display_taxonomy_badges();
```

**Display Pagination**:
```php
extrachill_pagination();
```

**Display Breadcrumbs**:
```php
extrachill_breadcrumbs();
```

## Documentation Index

### Core Features

- **Template Routing System** - WordPress native template routing with plugin override capability
- **Action Hooks Reference** - All action hooks for plugin integration
- **Filter Hooks Reference** - All filter hooks for customization
- **Theme Constants and Functions** - Core constants, configuration, utility functions

### Taxonomies and Content

- **Custom Taxonomies** - Location, Festival, Artist, Venue taxonomies
- **View Counting System** - Archive sorting uses `ec_post_views` (tracked by `extrachill-analytics`)
- **Category Rewrite System** - Clean category URLs without /category/ prefix

### Navigation and Menus

- **Navigation System** - Hook-based navigation with hardcoded menu performance
- **Archive Functionality** - Filtering, sorting, and archive features
- **Search System** - Multisite search with cross-site results

### Assets and Performance

- **Asset Loading System** - Conditional CSS/JS loading with cache busting
- **Custom Embeds** - Bandcamp and Instagram embed support

### Template Components

- **Template Components** - Pagination, breadcrumbs, post meta, social links, share buttons

### Multisite Integration

- **Multisite Integration** - WordPress multisite network integration

## Notes

### Template System

- WordPress native `template_include` filter routing
- Custom page template support (respects templates assigned via WordPress admin)
- Plugin override filters for all page types (except when custom templates assigned)
- Modular template organization
- `index.php` emergency fallback only

### Custom Taxonomies

- **Location** (hierarchical): Geographic content organization
- **Festival** (non-hierarchical): Festival event tags
- **Artist** (non-hierarchical): Musical artist tags with archive filtering
- **Venue** (non-hierarchical): Music venue tags

All taxonomies include REST API support and block editor integration.

### Navigation Architecture

- Hook-based menu system (no WordPress menu database queries)
- Hardcoded menu content for performance
- Plugin extensibility via action hooks
- Hamburger menu with flyout navigation
- Integrated search and social links

### Archive Features

- Post sorting (recent/oldest/popular)
- Sort dropdown includes `random` option
- Artist filtering (category-specific)
- Child term dropdowns
- URL parameter preservation
- Enhanced pagination with array-based support

### Search Features

- Multisite cross-site search (requires extrachill-multisite plugin)
- Main site post search
- Community forum search integration
- Site badges for result identification
- 10-minute result caching
- Share buttons on search results

### Asset Management

- 11 modular CSS files loaded conditionally
- Cache busting via `filemtime()`
- Root CSS variables for global theming
- JavaScript conditional loading including share.js and notice interactions
- FontAwesome SVG sprite with versioning and QR/download icons

### Embed Support

- Bandcamp album/track embeds
- Spotify embeds use WordPress core behavior
- Instagram integration
- YouTube (WordPress core)
- SoundCloud (WordPress core)

## Plugin Dependencies

### Required for Full Functionality

**Network-Activated Plugins**:

**extrachill-multisite**:
- Core multisite functionality
- Network security and admin tools
- Cross-domain authentication

**extrachill-search**:
- Cross-site search via `extrachill_multisite_search()`
- Multisite result integration with site badges

**extrachill-users**:
- Profile URL helpers (`ec_get_user_profile_url()`, `ec_get_user_author_archive_url()`)
- Avatar menu system
- Team member management

### Optional Integration

Theme integrations are intentionally minimal. Most advanced behavior (SEO, forums, e-commerce, etc.) is owned by plugins.

## Multisite Network

Theme serves the active sites in the Extra Chill Platform network (Blog ID 6 unused):

1. **extrachill.com** - Main music journalism and content site (Blog ID 1)
2. **community.extrachill.com** - Community forums and user hub (Blog ID 2)
3. **shop.extrachill.com** - E-commerce platform with WooCommerce (Blog ID 3)
4. **artist.extrachill.com** - Artist platform and profiles (Blog ID 4)
5. **chat.extrachill.com** - AI chatbot system (Blog ID 5)
6. **events.extrachill.com** - Event calendar hub powered by Data Machine (Blog ID 7)
7. **stream.extrachill.com** - Live streaming platform (Phase 1 UI) (Blog ID 8)
8. **newsletter.extrachill.com** - Newsletter management and archive hub (Blog ID 9)
9. **docs.extrachill.com** - Documentation hub (Blog ID 10)
10. **wire.extrachill.com** - Automated news feeds directory (Blog ID 11)
11. **horoscope.extrachill.com** - Horoscope functionality (Blog ID 12)

Each site uses the same theme with different plugin integrations and template overrides via `extrachill_template_*` filters. Cross-site features are handled by network-activated plugins (extrachill-multisite, extrachill-search, extrachill-users). The theme directly queries bbPress data for community activity with graceful fallback. The header includes a network navigation dropdown.

## Theme Support

### WordPress Features

- Responsive embeds
- Block styles
- Wide alignment
- Editor styles (4 CSS files)
- Custom logo (flexible dimensions)
- Post thumbnails
- HTML5 markup
- Automatic feed links
- Title tag support
- Page excerpts

### Block Editor

- Custom CSS variables in editor
- Editor-specific styles
- Responsive embed support
- Wide and full alignment
- Custom embed handlers

## Performance Features

- Conditional CSS/JS loading
- Hardcoded menus (no database queries)
- Cache busting via file modification time
- Dashicons removal for non-logged-in users
- Admin style prevention on frontend
- Image size optimization
- View count sorting via `ec_post_views` meta (tracking owned by `extrachill-analytics`)
- Notice system with cookie-based dismissal
- Share button interactions with clipboard API

## SEO Features

- Clean category URLs (no /category/ prefix)
- Semantic HTML structure
- Breadcrumb navigation
- Schema-ready markup

## Browser Support

- Modern browsers (Chrome, Firefox, Safari, Edge)
- Mobile responsive design
- Progressive enhancement

## Translation Ready

- Text domain: `extrachill`
- Translation directory: `/languages/`
- All strings properly internationalized
- WordPress translation functions throughout

## Support

See the platform-level [AGENTS.md](../AGENTS.md) for workflow and debugging expectations.

## Development Notes

When extending the theme:

1. Use provided action/filter hooks
2. Follow WordPress coding standards
3. Maintain separation of concerns (theme displays, plugins provide data)
4. Test on both extrachill.com and community.extrachill.com
5. Document custom hooks and filters

## Credits

**Developer**: Chris Huber (Chubes)
**Website**: https://chubes.net
**GitHub**: https://github.com/chubes4
**Platform**: https://extrachill.com

## License

WordPress Theme License
