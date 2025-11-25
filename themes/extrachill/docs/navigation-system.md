# Navigation System

Hook-based navigation architecture with hardcoded menu performance and plugin extensibility.

## Architecture Overview

The theme uses action hooks for menu content, eliminating WordPress menu database queries while maintaining extensibility.

**Core File**: `inc/header/navigation-menu.php`

## Components

### 1. Container Structure

Provides flyout menu HTML with hooks for content injection:

```php
<nav id="site-navigation" class="main-navigation">
    <button class="menu-toggle-container">...</button>
    <div id="primary-menu" class="flyout-menu">
        <div class="search-section">
            <?php extrachill_search_form(); ?>
        </div>
        <ul class="menu-items">
            <?php do_action('extrachill_navigation_main_menu'); ?>
            <?php do_action('extrachill_navigation_before_social_links'); ?>
            <li class="menu-social-links">
                <?php do_action( 'extrachill_social_links' ); ?>
            </li>
            <?php do_action('extrachill_navigation_bottom_menu'); ?>
        </ul>
    </div>
</nav>
```

### 2. Main Navigation Content

**Hook**: `extrachill_navigation_main_menu`
**Template**: `/inc/header/nav-main-menu.php`
**Default Items**:
- Community
- Calendar
- Festival Wire
- Blog Content

### 3. Bottom Navigation Content

**Hook**: `extrachill_navigation_bottom_menu`
**Template**: `/inc/header/nav-bottom-menu.php`
**Default Items**:
- About
- Contact

### 4. Footer Navigation

**Main Footer Hook**: `extrachill_footer_main_content`
**Template**: `/inc/footer/footer-main-menu.php`
**Structure**: Hierarchical footer menu with submenus

**Bottom Footer Hook**: `extrachill_below_copyright`
**Template**: `/inc/footer/footer-bottom-menu.php`
**Content**: Legal/policy links

### 5. Universal Back-to-Home Navigation

**Hook**: `extrachill_above_footer`
**Template**: `/inc/footer/back-to-home-link.php`
**Priority**: 20

**Smart Logic**:
- **Main Homepage** (extrachill.com front page): No button displayed
- **Subsite Homepages** (e.g., shop.extrachill.com, community.extrachill.com): Links to main site (https://extrachill.com) with "← Back to Main Site" label
- **All Other Pages**: Links to current site homepage with "← Back to Home" label

**Implementation**:
```php
function extrachill_display_back_to_home_link() {
    // No button on main site homepage
    if ( is_main_site() && is_front_page() ) {
        return;
    }

    // Determine URL and label based on context
    if ( ! is_main_site() && is_front_page() ) {
        // Subsite homepages link to main site
        $url   = 'https://extrachill.com';
        $label = '← Back to Main Site';
    } else {
        // All other pages link to current site homepage
        $url   = home_url();
        $label = '← Back to Home';
    }

    echo '<div class="back-to-home-container">';
    echo '<a href="' . esc_url( $url ) . '" class="button-1 button-large">' . esc_html( $label ) . '</a>';
    echo '</div>';
}
add_action( 'extrachill_above_footer', 'extrachill_display_back_to_home_link', 20 );
```

**Styling**: Uses `button-1 button-large` classes for consistent button appearance

## Search Integration

Search form integrated into navigation flyout:

```php
<div class="search-section">
    <?php extrachill_search_form(); ?>
</div>
```

**Function**: `extrachill_search_form()`
**Location**: `/inc/core/templates/searchform.php`

## Social Links Integration

Social media icons appear in navigation menu:

```php
<li class="menu-social-links">
    <?php do_action( 'extrachill_social_links' ); ?>
</li>
```

**Function**: `extrachill_social_links()`
**Location**: `/inc/core/templates/social-links.php`
**Icons**: Facebook, Twitter/X, Instagram, YouTube, Pinterest, GitHub

## Hamburger Toggle

Animated three-line menu toggle:

```php
<button class="menu-toggle-container" role="button" aria-expanded="false">
    <span class="menu-line top"></span>
    <span class="menu-line middle"></span>
    <span class="menu-line bottom"></span>
</button>
```

**JavaScript**: `/assets/js/nav-menu.js`
**Styling**: `/assets/css/nav.css`

## Search Icon

Magnifying glass icon triggers search:

```php
<div class="search-icon">
    <svg class="search-top">
        <use href="<?php echo get_template_directory_uri(); ?>/assets/fonts/fontawesome.svg?v=...#magnifying-glass-solid"></use>
    </svg>
</div>
```

**Icon Source**: FontAwesome SVG sprite with cache busting

## Plugin Extension

Plugins can add menu items via hooks:

```php
// Add custom menu item to main menu
add_action( 'extrachill_navigation_main_menu', function() {
    echo '<li><a href="/custom">Custom Link</a></li>';
}, 15 ); // Priority 15 = after defaults

// Add content before social links
add_action( 'extrachill_navigation_before_social_links', function() {
    echo '<li class="divider"></li>';
} );

// Add footer menu section
add_action( 'extrachill_footer_main_content', function() {
    echo '<div class="custom-footer-section">...</div>';
}, 20 );
```

## Menu Management Removed

WordPress menu management interface is hidden:

```php
function extrachill_remove_menu_admin_pages() {
    remove_submenu_page('themes.php', 'nav-menus.php');
}
add_action('admin_menu', 'extrachill_remove_menu_admin_pages', 999);

function extrachill_remove_customizer_menus($wp_customize) {
    $wp_customize->remove_panel('nav_menus');
}
add_action('customize_register', 'extrachill_remove_customizer_menus', 20);
```

**Reason**: Hardcoded menus eliminate database queries for better performance

## Modifying Menu Content

Edit menu items directly in template files:

**Main Menu**: `/inc/header/nav-main-menu.php`
**Bottom Menu**: `/inc/header/nav-bottom-menu.php`
**Footer Main**: `/inc/footer/footer-main-menu.php`
**Footer Bottom**: `/inc/footer/footer-bottom-menu.php`
**Back-to-Home**: `/inc/footer/back-to-home-link.php`

## Navigation Assets

**CSS**: `/assets/css/nav.css`
**JavaScript**: `/assets/js/nav-menu.js`
**Loading**: All pages via `extrachill_enqueue_navigation_assets()`

## Benefits

**Performance**: No database queries for menu generation
**Extensibility**: Plugins hook in without modifying templates
**Maintainability**: Menu content in focused, logical files
**Control**: Direct HTML control for complex menu structures
**Simplicity**: No WordPress menu configuration needed

## Accessibility

- ARIA labels on navigation elements
- `role="navigation"` on nav container
- `aria-expanded` state on toggle button
- Keyboard navigation support
- Screen reader friendly structure

## Mobile Responsiveness

Navigation adapts to mobile via CSS:
- Hamburger menu on small screens
- Full menu on desktop
- Touch-friendly tap targets
- Slide-in/out animation
