# ExtraChill WordPress Theme

A flexible, hook-based WordPress theme designed for music-focused websites. Features CSS variable customization, modular asset loading, and extensive filter/action hooks for plugin-based customization.

## Overview

ExtraChill is a modern, performance-optimized WordPress theme designed for music journalism, artist features, and creative content. It works as a standalone single-site theme or as part of a WordPress multisite network.

## Key Features

### Music-Focused Content Management
- **Custom Taxonomies**: Artist, Venue, Festival, and Location organization with REST API support
- **Homepage Content Delivery**: Hook-based front page powered by `extrachill_homepage_content` action
- **Share System**: Integrated share buttons with clipboard copy and social sharing
- **Notice System**: User feedback system supporting multiple notices with cookie-based dismissal

### Performance Optimizations
- **Conditional Asset Loading**: CSS/JS load only when needed based on page context
- **Modular CSS Architecture**: Page/component-specific stylesheets loaded conditionally
- **filemtime() Versioning**: Automatic cache busting for all assets
- **Minimal Dependencies**: No build step required for development

### Modern Design System
- **CSS Variables**: Global design tokens in `assets/css/root.css` - fully overridable via filter
- **Dark Mode Support**: Automatic dark mode via `prefers-color-scheme`
- **Responsive Design**: Mobile-first layouts with accessible navigation
- **Icon System**: SVG sprite system for consistent iconography

### Extensibility
- **67 Hooks**: Filters and actions for complete customization via plugins
- **Template Override System**: Plugins can override any template via filters
- **Graceful Degradation**: Works standalone or with companion plugins

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher

## Standalone Usage

This theme works as a standalone WordPress theme for any music-focused site. When used without companion plugins, it gracefully degrades with sensible defaults.

### Recommended Customization Approach

**Use a plugin, not a child theme.** This theme is designed with 67 hooks (filters and actions) that allow complete customization via plugins. A lightweight companion plugin provides cleaner separation of concerns and easier updates.

### Extension Points

Override theme styling without modifying theme files:

**CSS Variable Override** (`extrachill_css_variables`):
```php
add_filter( 'extrachill_css_variables', function( $vars ) {
    return array(
        '--accent'              => '#your-brand-color',
        '--accent-2'            => '#secondary-color',
        '--font-family-heading' => '"Your Font", sans-serif',
    );
} );
```

**DNS Prefetch Domains** (`extrachill_dns_prefetch_domains`):
```php
add_filter( 'extrachill_dns_prefetch_domains', function( $domains ) {
    $domains[] = '//your-cdn.com';
    return $domains;
} );
```

**Homepage Content** (`extrachill_homepage_content`):
```php
add_action( 'extrachill_homepage_content', function() {
    echo '<div class="my-homepage-content">Your content here</div>';
}, 10 );
```

**Template Overrides**:
```php
add_filter( 'extrachill_template_single_post', function( $template ) {
    return MY_PLUGIN_DIR . '/templates/custom-single.php';
} );
```

See `/docs/filter-hooks.md` and `/docs/action-hooks.md` for the complete list of hooks.

## Development

### Local Development

Direct file editing with no build step required.

### Build + Deployment

Production builds use `./build.sh` (symlinked to `/.github/build.sh`).

Build artifact: `build/extrachill.zip`

### File Structure

```
extrachill/
├── assets/
│   ├── css/                    # Modular CSS files
│   │   ├── root.css            # CSS custom properties
│   │   ├── archive.css         # Archive page styles
│   │   ├── single-post.css     # Single post styles
│   │   ├── taxonomy-badges.css # Base taxonomy badge styles
│   │   └── ...                 # Additional component styles
│   ├── js/                     # JavaScript files
│   └── fonts/                  # Local web fonts
├── inc/
│   ├── components/             # Reusable UI components
│   ├── archives/               # Archive page functionality
│   ├── core/                   # Core WordPress features
│   │   ├── templates/          # Shared template components
│   │   ├── editor/             # Custom embed handlers
│   │   ├── assets.php          # Asset management
│   │   ├── custom-taxonomies.php # Music taxonomies
│   │   └── template-router.php # Template routing
│   ├── footer/                 # Footer functionality
│   ├── header/                 # Header functionality
│   ├── home/                   # Homepage components
│   ├── sidebar/                # Sidebar functionality
│   └── single/                 # Single post/page features
└── style.css                   # Theme header + base styles
```

### Architecture

**Template Routing**: WordPress native `template_include` filter via `inc/core/template-router.php`

**Hook-Based Layout**: Header and footer use action hooks rather than WordPress menus

**Modular Assets**: CSS/JS loaded conditionally based on page context

**Security**: Output escaping, input sanitization, nonce verification throughout

## Custom Taxonomies

The theme registers four music-focused taxonomies:

- **Artist** (non-hierarchical) - Musical artists
- **Venue** (non-hierarchical) - Performance venues
- **Festival** (non-hierarchical) - Music festivals
- **Location** (hierarchical) - Geographic locations

All taxonomies have REST API support for block editor integration.

## Filterable Defaults

Many theme behaviors can be customized via filters:

| Filter | Purpose |
|--------|---------|
| `extrachill_single_post_style_post_types` | Post types that load single-post.css |
| `extrachill_sidebar_style_post_types` | Post types that load sidebar.css |
| `extrachill_sidebar_recent_posts_content` | Custom sidebar recent posts content |
| `extrachill_filter_bar_category_items` | Category-specific filter bar dropdowns |
| `extrachill_related_posts_allowed_taxonomies` | Taxonomies for related posts |
| `extrachill_footer_bottom_menu_items` | Footer bottom menu links |

## License

GNU General Public License v2 or later (GPL-2.0-or-later)

---

**Theme**: Extra Chill
**Author**: Chris Huber (https://chubes.net)
**License**: GPL-2.0-or-later
