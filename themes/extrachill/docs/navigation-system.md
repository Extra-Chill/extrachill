# Navigation System

Minimal header + hook-driven footer navigation.

## Header Search Overlay

The theme header is intentionally minimal:

- `header.php` renders branding + `do_action( 'extrachill_header_top_right' )`
- `inc/header/header-search.php` hooks into `extrachill_header_top_right` and renders the search icon + overlay panel
- `assets/js/nav-menu.js` controls open/close behavior for the search overlay

**Relevant files**:
- `header.php`
- `inc/header/header-search.php`
- `assets/js/nav-menu.js`

## Search Form

The search form comes from `extrachill_search_form()`.

- **Function**: `extrachill_search_form()`
- **Location**: `inc/core/templates/searchform.php`

## Secondary Header Navigation

Renders below the main header only when a plugin supplies items.

- **Action**: `extrachill_after_header`
- **Template**: `inc/header/secondary-header.php`
- **Filter**: `extrachill_secondary_header_items`

```php
add_filter( 'extrachill_secondary_header_items', function( $items ) {
    $items[] = array(
        'url'      => '/announcements',
        'label'    => 'Announcements',
        'priority' => 5,
    );

    return $items;
} );
```

## Footer Navigation

Footer menus are hardcoded template includes registered via theme actions.

- **Main footer action**: `extrachill_footer_main_content`
  - Default handler: `extrachill_default_footer_main_content()` in `inc/core/actions.php`
  - Template include: `inc/footer/footer-main-menu.php`

- **Below-menu action**: `extrachill_footer_below_menu`
  - Default handler: registered inside `inc/footer/footer-main-menu.php`
  - Purpose: renders `do_action( 'extrachill_render_newsletter_form', 'navigation' )`

- **Bottom footer action**: `extrachill_below_copyright`
  - Default handler: `extrachill_default_below_copyright()` in `inc/core/actions.php`
  - Template include: `inc/footer/footer-bottom-menu.php`

### Universal Back-to-Home Navigation

- **Action**: `extrachill_above_footer`
- **Template**: `inc/footer/back-to-home-link.php`

## Social Links

- **Action**: `extrachill_social_links`
- **Template helper**: `inc/core/templates/social-links.php`

## Admin Menu Management

WordPress menu UI is intentionally hidden by the theme:

- **Function**: `extrachill_remove_menu_admin_pages()`
- **Location**: `functions.php`
- **Hook**: `admin_menu` (priority 999)

## Assets

- **Search overlay script**: `assets/js/nav-menu.js` (enqueued by `extrachill_enqueue_navigation_assets()` in `inc/core/assets.php`)
- **No `nav.css` in theme**: navigation/search overlay styles live in `style.css` (plus other modular CSS files)

## Extensibility

- Prefer hooking into the existing theme actions/filters rather than replacing templates.
- Footer sections can be extended by adding handlers to `extrachill_footer_main_content`, `extrachill_footer_below_menu`, or `extrachill_below_copyright`.
