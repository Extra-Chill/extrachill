# Action Hooks Reference

The theme provides action hooks for plugin integration and customization throughout templates.

## Navigation Hooks

### extrachill_header_top_right
Populates the header area with the hamburger/navigation template, search icon, and related flyout markup.

**Location**: `inc/header/navigation-menu.php`
**Default Handler**: Anonymous function loaded at priority 10
**Outputs**: `<nav id="site-navigation" ...>...</nav>` plus the search icon, so plugins can replace the entire navigation block by unhooking this action.

### extrachill_navigation_main_menu
Main navigation menu content (login link + network hubs).

**Default Handler**: `extrachill_default_navigation_main_menu()`
**Default Template**: `/inc/header/nav-main-menu.php`
**Priority**: 10

```php
// Add custom menu item
add_action( 'extrachill_navigation_main_menu', function() {
    echo '<li><a href="/custom">Custom Link</a></li>';
}, 15 );
```

### extrachill_navigation_bottom_menu
Bottom navigation menu (About, Contact).

**Default Handler**: `extrachill_default_navigation_bottom_menu()`
**Default Template**: `/inc/header/nav-bottom-menu.php`
**Priority**: 10

### extrachill_navigation_before_social_links
Hook before social links in navigation.

**Usage**: Add custom elements before social media icons (e.g., login toggles, dividers). Use priorities ≥15 to append after default menu items.


## Footer + System Hooks

### extrachill_above_footer
Universal navigation hook before footer.

**Context**: Fires before footer on all pages throughout the site
**Usage**: Display universal navigation elements (e.g., back-to-home button with smart logic)
**Example Integration**: Theme displays context-aware back-to-home button (hidden on main homepage, links to main site on subsite homepages, links to current site homepage on all other pages)

```php
// Example usage
add_action( 'extrachill_above_footer', function() {
    echo '<div class="custom-nav-section">';
    echo '<a href="' . home_url() . '" class="button-1">Back to Home</a>';
    echo '</div>';
}, 20 );
```

### extrachill_before_footer
Online users stats display.

**Default Handler**: `extrachill_display_online_users_stats()`
**Default Template**: `/inc/footer/online-users-stats.php`
**Priority**: 10

**Requirements**: Requires `ec_get_online_users_count()` from extrachill-users plugin and switches to Blog ID 2 for total member counts

### extrachill_footer_main_content
Main footer menu with hierarchical structure.

**Default Handler**: `extrachill_default_footer_main_content()`
**Default Template**: `/inc/footer/footer-main-menu.php`
**Priority**: 10

### extrachill_below_copyright
Legal/policy links below copyright.

**Default Handler**: `extrachill_default_below_copyright()`
**Default Template**: `/inc/footer/footer-bottom-menu.php`
**Priority**: 10

## Homepage Hooks

### extrachill_homepage_content
Primary homepage content container.

**Default Handler**: None (plugins provide content)
**Default Template**: `/inc/home/templates/front-page.php`
**Priority**: 10

**Usage**: Single hook for hero/grid/body layout. Plugins render full homepage sections here.

```php
add_action( 'extrachill_homepage_content', function() {
    include MY_PLUGIN_DIR . '/templates/homepage-section.php';
} );
```

### extrachill_after_homepage_content
Footer/action slot rendered immediately after homepage content.

**Default Handler**: None (plugins provide CTA/footer blocks)
**Default Template**: `/inc/home/templates/front-page.php`
**Priority**: 20

**Usage**: Ideal for global CTAs, signup widgets, or analytics beacons that belong after all primary content.

```php
add_action( 'extrachill_after_homepage_content', 'my_plugin_homepage_cta', 20 );
```

### extrachill_notices
Centralized notice system for user feedback across the platform.

**Default Handler**: `extrachill_display_notices()`
**Default Template**: `/inc/core/notices.php`
**Priority**: 10

**Usage**: Display multiple notices with cookie-based dismissal and action buttons.

```php
add_action( 'extrachill_notices', function() {
    extrachill_add_notice( 'success', 'Operation completed successfully!' );
} );
```
## Single Post Hooks

### extrachill_above_post_title
Content above post title (taxonomy badges).

**Default Handler**: `extrachill_hook_taxonomy_badges_above_title()`
**Function**: Calls `extrachill_display_taxonomy_badges()`

### extrachill_comments_section
Comments display.

**Default Handler**: `extrachill_hook_comments_section()`
**Default Template**: `/inc/single/comments.php`

## Archive Hooks

### extrachill_archive_header
Archive page header.

**Default Handler**: `extrachill_default_archive_header()`
**Default Template**: `/inc/archives/archive-header.php`
**Priority**: 10

### extrachill_after_author_bio
Hook after author bio on author archive pages.

**Context**: Only fires on author archives (inside `is_author()` conditional) on first page (not paged)
**Parameters**: `$author_id` (int) - The queried author's user ID
**Usage**: Plugins add content after author bio (e.g., community profile links, social links, custom CTAs)
**Example Integration**: extrachill-users plugin displays "View Community Profile" button

```php
// Example usage
add_action( 'extrachill_after_author_bio', function( $author_id ) {
    echo '<div class="author-custom-content">';
    echo '<a href="/community/profile/' . $author_id . '">View Community Profile</a>';
    echo '</div>';
}, 10, 1 );
```

### extrachill_archive_above_posts
Filter bar above archive posts.

**Used By**: `extrachill_archive_filter_bar()` for sorting and filtering UI

### extrachill_archive_filter_bar
Inject navigational buttons/links into archive filter bar.

**Context**: Fires inside `<div id="extrachill-custom-sorting">` on all archive pages with filter bar
**Visual Position**: Buttons appear on right side of filter bar (use `float: right` styling)
**Usage**: Plugins add navigational buttons for archive-specific actions (e.g., view artist profile, browse location)
**Example Integration**: Theme displays "View Artist Profile" button on artist taxonomy archives
**Styling**: Plugins handle their own wrapper divs and styling (no automatic container)

```php
// Example usage
add_action( 'extrachill_archive_filter_bar', function() {
    if ( is_tax( 'artist' ) ) {
        $term = get_queried_object();
        echo '<div class="custom-nav-button" style="float: right; margin-left: 1em;">';
        echo '<a href="/artist-profile/' . $term->slug . '" class="button-2">View Profile</a>';
        echo '</div>';
    }
} );

### extrachill_search_header
Search results header.

**Default Handler**: `extrachill_default_search_header()`
**Default Template**: `/inc/archives/search/search-header.php`
**Priority**: 10 (plugins can append additional controls at >10)

## Social Links Hook

### extrachill_social_links
Social media icon display.

**Default Handler**: `extrachill_social_links()`
**Location**: `/inc/core/templates/social-links.php`
**Priority**: 10

Displays: Facebook, Twitter/X, Instagram, YouTube, Pinterest, GitHub

## Share Button Hook

### extrachill_share_button
Share button component with clipboard copy and social sharing.

**Default Handler**: `extrachill_share_button()`
**Location**: `/inc/core/templates/share.php`
**Priority**: 10

**Features**: Facebook, Twitter/X, Email, Copy Link with clipboard API fallback

## Priority Guidelines

- **5**: Load before defaults
- **10**: Default handlers
- **15**: After defaults
- **20**: Late modifications
