# Quick Reference Guide

Essential functions, hooks, and patterns for ExtraChill theme development.

## Template Functions

### View Counting
```php
// Display view count
ec_the_post_views();                    // Outputs: 1,234 views

// Get view count
$views = ec_get_post_views();           // Returns: 1234

// Get formatted string
$text = ec_the_post_views( null, false ); // Returns: "1,234 views"
```

### Pagination
```php
// Standard pagination
extrachill_pagination();

// Custom query pagination
$query = new WP_Query( $args );
extrachill_pagination( $query, 'archive' );
```

### Post Meta
```php
// Display post metadata (date, author, updated)
extrachill_entry_meta();
```

### Taxonomy Badges
```php
// Display all taxonomy badges
extrachill_display_taxonomy_badges();

// Custom wrapper
extrachill_display_taxonomy_badges( null, array(
    'wrapper_class' => 'custom-badges',
    'show_wrapper'  => true,
) );
```

### Breadcrumbs
```php
// Display breadcrumb navigation
extrachill_breadcrumbs();
```

### Social Links
```php
// Display social media icons
do_action( 'extrachill_social_links' );
```

### Share Button
```php
// Display share button
do_action( 'extrachill_share_button' );

// Custom share data
extrachill_share_button( array(
    'share_url'   => 'https://example.com',
    'share_title' => 'Custom Title',
) );

// Add notice
extrachill_add_notice( 'success', 'Operation completed!', array(
    'dismissible' => true,
    'action_url'  => '/dashboard',
    'action_text' => 'Go to Dashboard'
) );

// Display notices
do_action( 'extrachill_notices' );
```

### Search Form
```php
// Display search form
extrachill_search_form();
```

### Community Activity
```php
// Get activity items from community.extrachill.com (blog 2)
$items = extrachill_get_community_activity_items( 5 );

// Render with default styling
extrachill_render_community_activity();

// Render with custom configuration
extrachill_render_community_activity( array(
    'limit'          => 10,
    'wrapper_class'  => 'custom-activity-list',
    'item_class'     => 'custom-activity-card',
    'render_wrapper' => true,
) );

// Use pre-fetched items
$items = extrachill_get_community_activity_items( 5 );
extrachill_render_community_activity( array(
    'items'          => $items,
    'render_wrapper' => false,
) );
```

## Template Override Filters

```php
// Override homepage template
add_filter( 'extrachill_template_homepage', function( $template ) {
    return MY_PLUGIN_DIR . '/custom-homepage.php';
} );

// Override single post template
add_filter( 'extrachill_template_single_post', function( $template ) {
    return MY_PLUGIN_DIR . '/custom-single.php';
} );

// Override page template
add_filter( 'extrachill_template_page', function( $template ) {
    return MY_PLUGIN_DIR . '/custom-page.php';
} );

// Override archive template
add_filter( 'extrachill_template_archive', function( $template ) {
    return MY_PLUGIN_DIR . '/custom-archive.php';
} );

// Override search template
add_filter( 'extrachill_template_search', function( $template ) {
    return MY_PLUGIN_DIR . '/custom-search.php';
} );
```

## Navigation Hooks

```php
// Add main navigation item (after default network links)
add_action( 'extrachill_navigation_main_menu', function() {
    echo '<li><a href="/custom">Custom Link</a></li>';
}, 15 );

// Add bottom navigation item
add_action( 'extrachill_navigation_bottom_menu', function() {
    echo '<li><a href="/about">About</a></li>';
}, 15 );

// Add content before social links
add_action( 'extrachill_navigation_before_social_links', function() {
    echo '<li class="divider"></li>';
}, 15 );

// Add footer content
add_action( 'extrachill_footer_main_content', function() {
    echo '<div>Custom footer content</div>';
}, 20 );

// Add content below copyright
add_action( 'extrachill_below_copyright', function() {
    echo '<div>Additional legal links</div>';
}, 15 );

// Online users widget already hooked at priority 10 (no custom action needed)
```

## Homepage Hooks

```php
// Add homepage content
add_action( 'extrachill_homepage_content', function() {
    include MY_PLUGIN_DIR . '/templates/homepage-section.php';
}, 10 );

// Append CTA after homepage content
add_action( 'extrachill_after_homepage_content', function() {
    include MY_PLUGIN_DIR . '/templates/homepage-cta.php';
}, 20 );
```

## Archive Hooks

```php
// Add archive header content
add_action( 'extrachill_archive_header', function() {
    echo '<div>Custom archive header</div>';
}, 15 );

// Add content above posts (before filter bar renders)
add_action( 'extrachill_archive_above_posts', function() {
    echo '<div>Custom filter</div>';
}, 5 );
```

## Content Filters

```php
// Customize post meta
add_filter( 'extrachill_post_meta', function( $meta, $post_id, $post_type ) {
    $meta .= '<div>Custom: ' . get_post_meta( $post_id, 'custom', true ) . '</div>';
    return $meta;
}, 10, 3 );

// Disable sticky header
add_filter( 'extrachill_enable_sticky_header', '__return_false' );

// Conditional sticky header
add_filter( 'extrachill_enable_sticky_header', function( $enabled ) {
    return ! is_page( 'no-sticky' );
} );

// Customize Bandcamp embed
add_filter( 'custom_bandcamp_embed', function( $embed_code, $matches ) {
    return str_replace( 'width: 400px', 'width: 100%', $embed_code );
}, 10, 2 );

// Add secondary header items
add_filter( 'extrachill_secondary_header_items', function( $items ) {
    $items[] = array(
        'url'      => '/announcements',
        'label'    => 'Announcements',
        'priority' => 5,
    );
    return $items;
} );

// Customize footer bottom menu
add_filter( 'extrachill_footer_bottom_menu_items', function( $items ) {
    $items[] = array(
        'url'      => '/terms',
        'label'    => 'Terms of Service',
        'priority' => 15,
    );
    return $items;
} );
```

## Query Modifications

```php
// Modify archive query
add_action( 'pre_get_posts', function( $query ) {
    if ( $query->is_archive() && $query->is_main_query() ) {
        $query->set( 'posts_per_page', 20 );
    }
} );

// Modify search query
add_action( 'pre_get_posts', function( $query ) {
    if ( $query->is_search() && $query->is_main_query() ) {
        $query->set( 'post_type', array( 'post', 'page' ) );
    }
} );
```

## Custom Taxonomy Queries

```php
// Query by artist
$args = array(
    'post_type' => 'post',
    'tax_query' => array(
        array(
            'taxonomy' => 'artist',
            'field'    => 'slug',
            'terms'    => 'artist-slug',
        ),
    ),
);
$query = new WP_Query( $args );

// Query by location
$args = array(
    'tax_query' => array(
        array(
            'taxonomy' => 'location',
            'field'    => 'slug',
            'terms'    => 'denver',
        ),
    ),
);
```

## Asset Enqueueing

```php
// Add custom CSS
add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'my-custom-style',
        MY_PLUGIN_URL . '/css/custom.css',
        array( 'extrachill-root' ), // Depend on root variables
        filemtime( MY_PLUGIN_DIR . '/css/custom.css' )
    );
} );

// Add custom JavaScript
add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'my-custom-script',
        MY_PLUGIN_URL . '/js/custom.js',
        array( 'jquery' ),
        filemtime( MY_PLUGIN_DIR . '/js/custom.js' ),
        true // Load in footer
    );
} );
```

## Theme Constants

```php
// Theme directory
EXTRACHILL_PARENT_DIR           // /path/to/themes/extrachill

// Includes directory
EXTRACHILL_INCLUDES_DIR         // /path/to/themes/extrachill/inc

// Usage
require_once( EXTRACHILL_INCLUDES_DIR . '/custom/file.php' );
```

## Conditional Tags

```php
// Check if multisite function available
if ( function_exists( 'extrachill_multisite_search' ) ) {
    $results = extrachill_multisite_search( $query );
}

// Check for Co-Authors Plus
if ( is_plugin_active( 'co-authors-plus/co-authors-plus.php' ) ) {
    coauthors_posts_links();
}
```

## URL Parameters

```php
// Archive sorting
/category-slug/?sort=oldest
/category-slug/?sort=recent (default)
/category-slug/?sort=random

// Artist filtering
/song-meanings/?artist=artist-slug

// Combined parameters
/category/?artist=artist-slug&sort=oldest
```

## Multisite Integration

```php
// Cross-site search (extrachill-search plugin)
if ( function_exists( 'extrachill_multisite_search' ) ) {
    $results = extrachill_multisite_search( get_search_query() );
}

// User profile URL helpers (extrachill-users plugin)
if ( function_exists( 'ec_get_user_profile_url' ) ) {
    $profile_url = ec_get_user_profile_url( $user_id );
}

if ( function_exists( 'ec_get_user_author_archive_url' ) ) {
    $author_archive_url = ec_get_user_author_archive_url( $user_id );
}

// Shared community activity helper (queries both blog ID 2 and 4)
$items = extrachill_get_community_activity_items( 5 );
extrachill_render_community_activity( array(
    'items'          => $items,
    'render_wrapper' => false,
    'item_class'     => 'sidebar-activity-card',
) );
```

## Custom Post Meta

```php
// Get post views
$views = get_post_meta( $post_id, 'ec_post_views', true );

// Update post views
update_post_meta( $post_id, 'ec_post_views', $views + 1 );
```

## Common Patterns

### Adding Plugin Menu Items
```php
add_action( 'extrachill_navigation_main_menu', function() {
    if ( is_user_logged_in() ) {
        echo '<li><a href="/dashboard">Dashboard</a></li>';
    }
}, 15 );
```

### Custom Archive Filtering
```php
add_action( 'extrachill_archive_above_posts', function() {
    if ( is_category( 'special' ) ) {
        echo '<div class="custom-filter">
            <select onchange="window.location.href=this.value;">
                <option>Filter...</option>
            </select>
        </div>';
    }
}, 5 );
```

### Modifying Template Output
```php
add_filter( 'extrachill_template_archive', function( $template ) {
    if ( is_category( 'special' ) ) {
        return MY_PLUGIN_DIR . '/templates/special-archive.php';
    }
    return $template;
} );
```

### Sticky Header Control
```php
// Disable sticky header globally
add_filter( 'extrachill_enable_sticky_header', '__return_false' );

// Disable conditionally
add_filter( 'extrachill_enable_sticky_header', function( $enabled ) {
    // Disable on mobile devices
    return ! wp_is_mobile();
} );

// Disable on specific pages
add_filter( 'extrachill_enable_sticky_header', function( $enabled ) {
    return ! is_page( array( 'landing', 'splash' ) );
} );
```

### Adding Custom Metadata
```php
add_filter( 'extrachill_post_meta', function( $meta, $post_id ) {
    $custom = get_post_meta( $post_id, 'custom_field', true );
    if ( $custom ) {
        $meta .= '<div class="custom-meta">' . esc_html( $custom ) . '</div>';
    }
    return $meta;
}, 10, 2 );
```

## Priority Guidelines

- **5**: Before defaults (override/prepend)
- **10**: Default handlers
- **15**: After defaults (extend/append)
- **20**: Late modifications
- **100**: Very late (cleanup/final touches)
