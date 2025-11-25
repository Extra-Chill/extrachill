# Action Hooks Reference

The theme provides action hooks for plugin integration and customization throughout templates.

## Navigation Hooks

### extrachill_navigation_main_menu
Main navigation menu content.

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

**Usage**: Add custom elements before social media icons.

## Footer Hooks

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

### extrachill_homepage_hero
Hero section display.

**Default Handler**: `extrachill_default_homepage_hero()`
**Default Template**: `/inc/home/templates/hero.php`
**Priority**: 10

### extrachill_homepage_content_top
3x3 grid section.

**Default Handler**: `extrachill_default_homepage_content_top()`
**Default Template**: `/inc/home/templates/section-3x3-grid.php`
**Priority**: 10

### extrachill_homepage_content_middle
Recent posts section.

**Default Handler**: `extrachill_default_homepage_content_middle()`
**Default Template**: `/inc/home/templates/section-more-recent-posts.php`
**Priority**: 10

### extrachill_homepage_content_bottom
ExtraChill link section.

**Default Handler**: `extrachill_default_homepage_content_bottom()`
**Default Template**: `/inc/home/templates/section-extrachill-link.php`
**Priority**: 10

### extrachill_home_final_left
About section.

**Default Handler**: `extrachill_default_final_left()`
**Default Template**: `/inc/home/templates/section-about.php`
**Priority**: 10

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
**Priority**: 10

## Social Links Hook

### extrachill_social_links
Social media icon display.

**Default Handler**: `extrachill_social_links()`
**Location**: `/inc/core/templates/social-links.php`
**Priority**: 10

Displays: Facebook, Twitter/X, Instagram, YouTube, Pinterest, GitHub

## Share Button Hook

### extrachill_share_button
Share button component.

**Default Handler**: `extrachill_share_button()`
**Location**: `/inc/core/templates/share.php`
**Priority**: 10

## Priority Guidelines

- **5**: Load before defaults
- **10**: Default handlers
- **15**: After defaults
- **20**: Late modifications
