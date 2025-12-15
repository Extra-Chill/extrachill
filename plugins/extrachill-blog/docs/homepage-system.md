# Blog Homepage System

## Overview

The homepage system powers extrachill.com's (Blog ID 1) custom landing page with multiple content sections, hero imagery, and featured post grids. The system uses a hook-based architecture allowing flexibility and extensibility.

## Directory Structure

**Location**: `inc/home/`

```
inc/home/
├── homepage-hooks.php          # Hook registration and template loader
├── homepage-queries.php         # WP_Query configurations and data fetching
└── templates/
    ├── hero.php                 # Hero section with imagery
    ├── section-about.php        # About section (platform overview)
    ├── section-3x3-grid.php     # Featured posts 3x3 grid
    ├── section-extrachill-link.php # Artist link pages promo
    └── section-more-recent-posts.php # Blog roll / recent posts
```

## Template Hierarchy

The homepage is built from discrete template sections that can be independently overridden:

1. **Hero Section** - Large featured image and headline
2. **About Section** - Platform mission/overview
3. **3x3 Grid** - Featured content display
4. **extrachill.link Promo** - Call-to-action for artist link pages
5. **Recent Posts** - Blog chronological listing

Each section is a separate template file loaded via the hook system.

## Hook System

### Primary Hook: `extrachill_homepage_content`

Called once in theme's homepage rendering loop. Used by plugins to inject homepage sections.

**Usage**:
```php
add_action('extrachill_homepage_content', function() {
    // Render custom homepage section
    include plugin_dir_path(__FILE__) . 'templates/my-section.php';
});
```

## Custom WordPress Hooks Provided

### Homepage Customization Hooks

The plugin registers these hooks for theme/plugin extensions:

- **`extrachill_homepage_hero_content`** - Modify hero section
  - Parameters: none
  - Use: Override hero image, headline, CTA text

- **`extrachill_homepage_featured_posts_query`** - Modify 3x3 grid query
  - Parameters: `$query_args` (WP_Query arguments array)
  - Return: Modified query array
  - Use: Change featured post selection criteria, limit, ordering

- **`extrachill_homepage_recent_posts_query`** - Modify blog roll query
  - Parameters: `$query_args` (WP_Query arguments array)
  - Return: Modified query array
  - Use: Filter by category, author, custom meta

- **`extrachill_homepage_sections`** - Register new homepage sections
  - Parameters: `$sections` (array of section callbacks)
  - Return: Modified sections array
  - Use: Add custom sections to homepage

- **`extrachill_homepage_rendered`** - After all sections render
  - Parameters: none
  - Use: Footer or cleanup actions after homepage display

### Example Hook Usage

```php
// Override featured posts query
add_filter('extrachill_homepage_featured_posts_query', function($args) {
    $args['tax_query'] = array(
        array(
            'taxonomy' => 'category',
            'field'    => 'slug',
            'terms'    => 'music-news'
        )
    );
    return $args;
});
```

## Archive System

**Location**: `inc/archive/`

```
inc/archive/
├── blog-archive-routing.php    # Template override & context detection
├── breadcrumbs.php              # Breadcrumb navigation
└── (search integration)
```

### Archive Template Override

The plugin overrides archive templates using WordPress's native `template_include` filter. This allows dynamic archive layouts based on context (category, tag, author, date).

**Supported Archive Types**:
- Category archives (`/category/music-news/`)
- Tag archives (`/tag/festival-coverage/`)
- Author archives (`/author/chris-huber/`)
- Date archives (`/2025/01/`)
- Search results (`/?s=query`)

### Breadcrumb Integration

Breadcrumbs display navigation hierarchy:
- **Homepage**: Root breadcrumb
- **Category Page**: Home > Category Name
- **Single Post**: Home > Category > Post Title
- **Date Archive**: Home > Year > Month > Day
- **Author Archive**: Home > By Author > Author Name

Breadcrumbs integrate with multisite network dropdown (when extrachill-search plugin active).

## Archive Filter Bar

**Component**: `archive-filter-bar.php`

Provides taxonomies and sorting options on archive pages:
- Category dropdown (for tag archives)
- Festival/Venue filters (custom taxonomies)
- Sort by: Newest, Oldest, Most Viewed
- Search within archive results

## Post Card Template

**Component**: `post-card.php`

Standardized post display template used in archive loops:
- Featured image (if available)
- Post title (linked)
- Author name and date
- Post excerpt
- Read More link
- Tax badges (categories, festival tags)

## CSS Asset Management

**Location**: `assets/css/home.css`

The homepage CSS handles:
- Hero section responsive layout
- 3x3 grid responsive behavior (3 columns on desktop, 2 on tablet, 1 on mobile)
- Section spacing and typography
- Feature section styling
- CTA button styling

Conditional loading:
```php
// CSS loaded only when displaying homepage
if (is_front_page()) {
    wp_enqueue_style('blog-home', get_template_directory_uri() . '/assets/css/home.css');
}
```

## Integration Points

### With Theme

The plugin works seamlessly with the extrachill theme:
- Uses theme's `root.css` CSS variables
- Respects theme typography and color system
- Renders within theme's layout structure
- Compatible with theme's responsive design
- Uses theme hooks for consistent integration

### With multisite

- Site-activated on Blog ID 1 only
- Uses hardcoded blog_id=1 for feature detection
- Post queries scoped to current blog
- Navigation links use multisite-aware URL functions

### With Other Plugins

**extrachill-multisite**: Uses breadcrumb integration for network dropdown

**extrachill-search**: Archive filter bar integrates with multisite search

## Query Architecture

### Homepage Featured Posts Query

**File**: `homepage-queries.php`

Configuration:
```php
$featured_args = array(
    'post_type'      => 'post',
    'posts_per_page' => 9,  // 3x3 grid
    'orderby'        => 'meta_value_num',
    'meta_key'       => '_featured_order',
    'order'          => 'ASC',
    'meta_query'     => array(
        array('key' => '_is_featured', 'value' => '1')
    )
);
```

Developers can override via `extrachill_homepage_featured_posts_query` filter.

### Recent Posts Query

**Default**: Latest 10 posts ordered by date descending

Can be filtered via `extrachill_homepage_recent_posts_query`:
```php
add_filter('extrachill_homepage_recent_posts_query', function($args) {
    $args['posts_per_page'] = 20; // Show 20 instead of 10
    $args['category__not_in'] = [5]; // Exclude category 5
    return $args;
});
```

## Performance Optimization

**Techniques Used**:
- Direct `require_once` for template files (no autoloading overhead)
- Single WP_Query for featured posts, separate query for blog roll
- Conditional asset loading (CSS only on front_page)
- Post meta field-based queries for featured post selection
- Caching via WordPress transients (optional enhancement)

## Customization Patterns

### Override Hero Section

```php
// In theme's functions.php or custom plugin
add_action('extrachill_homepage_content', function() {
    include get_template_directory() . '/custom-templates/my-hero.php';
}, 5); // Priority 5 ensures this loads first

// Remove default hero
remove_action('extrachill_homepage_content', 'extrachill_blog_hero_section', 10);
```

### Add Custom Section

```php
add_action('extrachill_homepage_content', function() {
    ?>
    <section class="custom-homepage-section">
        <h2>My Custom Section</h2>
        <?php
        $posts = get_posts([
            'post_type' => 'page',
            'numberposts' => 3,
            'meta_key' => '_template',
            'meta_value' => 'my-template'
        ]);
        foreach ($posts as $post) {
            // Render post
        }
        ?>
    </section>
    <?php
}, 15); // After default sections
```

### Modify Featured Posts Selection

```php
add_filter('extrachill_homepage_featured_posts_query', function($args) {
    // Show only festival-tagged posts
    $args['tax_query'] = array(
        array('taxonomy' => 'festival', 'operator' => 'EXISTS')
    );
    // Limit to last 7 days
    $args['date_query'] = array(
        array('after' => '7 days ago', 'inclusive' => true)
    );
    return $args;
});
```

## Related Documentation

- **AGENTS.md**: Architecture and development patterns
- **Gutenberg Blocks**: See blocks documentation for interactive blocks
- **Theme Integration**: See extrachill theme documentation
