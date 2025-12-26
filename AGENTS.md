# Extra Chill Theme - Architecture & Development Guide

WordPress theme serving the Extra Chill Platform multisite network across 9 active sites (Blog IDs 1-5, 7-10). This document provides architectural guidance for theme development and integration with the platform ecosystem.

## Theme Architecture & Organization

### File Structure Overview

The theme uses a modular architecture with direct `require_once` includes for all PHP functionality:

```
extrachill/
├── functions.php                # Theme bootstrap and initialization
├── header.php                   # HTML head, navigation, header content
├── footer.php                   # Footer navigation, scripts, HTML close
├── sidebar.php                  # Sidebar template with hook extensibility
├── index.php                    # Emergency fallback template only
├── style.css                    # Main theme stylesheet
├── assets/
│   ├── css/                     # 12 modular CSS files
│   │   ├── root.css             # CSS variables and design tokens
│   │   ├── archive.css          # Archive page styles
│   │   ├── filter-bar.css       # Universal filter bar component styles
│   │   ├── single-post.css      # Single post styles
│   │   ├── network-dropdown.css # Network site selector
│   │   ├── search.css           # Search results styles
│   │   ├── editor-style.css     # Block editor styles
│   │   ├── share.css            # Share button styles
│   │   ├── shared-tabs.css      # Tab/accordion interface styles
│   │   ├── sidebar.css          # Sidebar widget styles
│   │   └── taxonomy-badges.css  # Taxonomy badge styles
│   ├── js/                      # 4 JavaScript files
│   │   ├── nav-menu.js          # Navigation menu functionality
│   │   ├── network-dropdown.js  # Network site dropdown
│   │   ├── share.js             # Share button interactions
│   │   └── view-tracking.js     # Async view counting
│   └── fonts/                   # Local web fonts
└── inc/                         # Modular functionality
    ├── components/              # Reusable UI components
    │   ├── filter-bar.php       # Universal filter bar component
    │   └── filter-bar-defaults.php # Filter bar default configurations
    ├── core/                    # Core WordPress features
    │   ├── actions.php          # WordPress action hooks
    │   ├── assets.php           # Asset enqueuing and versioning
    │   ├── custom-taxonomies.php
    │   ├── icons.php            # SVG icon sprite system
    │   ├── notices.php          # Unified notice system
    │   ├── rewrite.php          # Category base rewriting
    │   ├── template-router.php  # Universal template routing
    │   ├── view-counts.php      # Post view tracking system
    │   ├── templates/           # Reusable template components
    │   │   ├── 404.php
    │   │   ├── breadcrumbs.php
    │   │   ├── community-activity.php  # Shared activity helper
    │   │   ├── no-results.php
    │   │   ├── pagination.php
    │   │   ├── post-meta.php
    │   │   ├── searchform.php
    │   │   ├── share.php
    │   │   ├── social-links.php
    │   │   ├── taxonomy-badges.php
    │   │   └── network-dropdown.php
│   └── editor/              # Custom embed handlers
│       ├── bandcamp-embeds.php
│       └── instagram-embeds.php
├── archives/                # Archive page functionality
│   ├── archive.php
│   ├── archive-custom-sorting.php
│   ├── archive-header.php
│   ├── post-card.php
│   └── search/
│       └── search-header.php
    ├── footer/                  # Footer functionality
    │   ├── back-to-home-link.php
    │   ├── footer-main-menu.php
    │   └── online-users-stats.php
    ├── header/                  # Header functionality
    │   ├── header-search.php
    │   └── secondary-header.php
    ├── home/                    # Homepage components
    │   └── templates/
    │       └── front-page.php
    ├── sidebar/                 # Sidebar widgets
    │   ├── community-activity.php
    │   └── recent-posts.php
    └── single/                  # Single post/page features
        ├── comments.php
        ├── related-posts.php
        ├── single-page.php
        └── single-post.php
```

### Loading Pattern

All PHP functionality loads via direct `require_once` includes in `functions.php`:

```php
// Define constants
define('EXTRACHILL_PARENT_DIR', get_template_directory());
define('EXTRACHILL_INCLUDES_DIR', EXTRACHILL_PARENT_DIR . '/inc');

// Load core functionality (28 direct includes)
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/post-meta.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/actions.php');
// ... additional includes ...
```

**Advantages**: Direct includes make dependency tree immediately visible, support WordPress conventions, eliminate autoloader complexity.

## Template System

### WordPress Native Template Hierarchy

The theme implements complete WordPress template hierarchy via `/inc/core/template-router.php`:

- **header.php** - Site header, navigation, opening HTML tags
- **footer.php** - Site footer, closing HTML tags
- **sidebar.php** - Sidebar content with hook extensibility
- **index.php** - Emergency fallback only (minimal markup)

### Template Routing & Plugin Overrides

The `template_include` filter in `template-router.php` supports:

```php
// Plugins can override any template completely
add_filter('extrachill_template_homepage', function($template) {
    return plugin_dir_path(__FILE__) . 'my-homepage.php';
});

// Available override hooks:
// - extrachill_template_homepage
// - extrachill_template_single_post
// - extrachill_template_page (only when no custom template)
// - extrachill_template_archive
// - extrachill_template_search
// - extrachill_template_404
// - extrachill_template_fallback
```

**Key Points**:
- All templates have access to global WordPress variables (`$post`, `$wp_query`, etc.)
- Plugin templates override theme templates via filters
- Template routing respects WordPress conditional tags (`is_home()`, `is_singular()`, etc.)
- Maintains compatibility with bbPress, WooCommerce, and specialized plugins

### Homepage Template Architecture

Homepage uses single hook container pattern (`inc/home/templates/front-page.php`):

```php
// Primary homepage content (plugins hook here)
do_action('extrachill_homepage_content');

// Footer/CTA slot (plugins hook here)
do_action('extrachill_after_homepage_content');
```

Plugins use these hooks to add homepage content blocks without template modifications.

## root.css CSS Variable System

### Design Tokens

**root.css** is the single source of truth for all CSS variables:

#### Color Variables
```css
/* Semantic colors */
--background-color: #fff;           /* Page background */
--text-color: #000;                 /* Primary text */
--link-color: #0b5394;              /* Links */
--border-color: #ddd;               /* Borders and dividers */
--accent: #53940b;                  /* Primary accent (green) */
--accent-2: #36454F;                /* Secondary accent (slate) */
--accent-3: #00c8e3;                /* Tertiary accent (cyan) */
--error-color: #dc3232;             /* Error/danger states */
--success-color: #28a745;           /* Success states */
--muted-text: #6b7280;              /* Secondary text */
--card-background: #f1f5f9;         /* Card backgrounds */
```

#### Typography Variables
```css
/* Font families */
--font-family-heading: "Loft Sans", sans-serif;     /* Headings */
--font-family-body: 'Helvetica', 'Open Sans', serif; /* Body text */
--font-family-brand: "Lobster", sans-serif;         /* Branding */
--font-family-mono: 'Helvetica', Arial, sans-serif; /* Code */

/* Font sizes (rem scale) */
--font-size-xs: 0.625rem;           /* Extra small (10px) */
--font-size-sm: 0.8125rem;          /* Small (13px) */
--font-size-base: 1rem;             /* Base (16px) */
--font-size-body: 1.125rem;         /* Body (18px) */
--font-size-lg: 1.25rem;            /* Large (20px) */
--font-size-xl: 1.5rem;             /* Extra large (24px) */
--font-size-2xl: 1.75rem;           /* 2x large (28px) */
--font-size-3xl: 2rem;              /* 3x large (32px) */
--font-size-brand: 2.25rem;         /* Brand (36px) */
```

#### Layout Variables
```css
/* Container widths */
--container-width: 1200px;          /* Standard content width */
--content-width: 800px;             /* Single column content */
--container-wide: 1600px;           /* Wide layouts */
--sidebar-width: 380px;             /* Sidebar width */
--form-width: 500px;                /* Form width */

/* Spacing scale (rem) */
--spacing-xs: 0.25rem;              /* 4px */
--spacing-sm: 0.5rem;               /* 8px */
--spacing-md: 1rem;                 /* 16px */
--spacing-lg: 1.5rem;               /* 24px */
--spacing-xl: 2rem;                 /* 32px */
```

#### Border & Focus Variables
```css
/* Border radius */
--border-radius-sm: 5px;            /* Small corners */
--border-radius-md: 8px;            /* Medium corners */
--border-radius-lg: 10px;           /* Large corners */
--border-radius-xl: 14px;           /* Extra large corners */
--border-radius-pill: 50px;         /* Pill shape */
--border-radius-circle: 50%;        /* Circle */

/* Focus states */
--focus-border-color: #53940b;      /* Focus border */
--focus-box-shadow: 0 0 0 3px rgba(83, 148, 11, 0.2); /* Focus glow */
```

### Dark Mode Support

All variables redefined in `@media (prefers-color-scheme: dark)`:

```css
@media (prefers-color-scheme: dark) {
    :root {
        --background-color: #1a1a1a;
        --text-color: #e5e5e5;
        /* ... all other variables for dark mode ... */
    }
}
```

### Using CSS Variables

Modular stylesheets consume root variables:

```css
/* archive.css */
.archive-header {
    background: var(--background-color);
    color: var(--text-color);
    padding: var(--spacing-lg);
}

.archive-title {
    font-family: var(--font-family-heading);
    font-size: var(--font-size-2xl);
    color: var(--accent);
}
```

**No !important**: Use CSS specificity and proper inheritance instead of !important.

## Asset Loading Strategy

### Enqueuing Pattern

All assets load conditionally in `/inc/core/assets.php`:

```php
function extrachill_enqueue_styles() {
    // Root variables always load
    wp_enqueue_style(
        'extrachill-root',
        get_template_directory_uri() . '/assets/css/root.css',
        [],
        filemtime(get_template_directory() . '/assets/css/root.css')
    );
    
    // Archive styles load only on archive pages
    if (is_archive()) {
        wp_enqueue_style(
            'extrachill-archive',
            get_template_directory_uri() . '/assets/css/archive.css',
            ['extrachill-root'],
            filemtime(get_template_directory() . '/assets/css/archive.css')
        );
    }
    
    // Single post styles load only on single posts
    if (is_singular('post')) {
        wp_enqueue_style(
            'extrachill-single-post',
            get_template_directory_uri() . '/assets/css/single-post.css',
            ['extrachill-root'],
            filemtime(get_template_directory() . '/assets/css/single-post.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_styles');
```

### Dependency Management

All stylesheets depend on root handle:

```php
// All CSS files depend on root.css for variables
wp_enqueue_style('archive-css', $url, ['extrachill-root'], $version);
```

### Versioning with filemtime()

Cache busting uses file modification time:

```php
// Automatically invalidate cache when file changes
wp_enqueue_style('theme-css', $url, [], filemtime($file_path));
```

### Asset Context Examples

```php
// Load only on front page
if (is_front_page()) {
    wp_enqueue_script('homepage-script', $url, [], $version);
}

// Load only on search results
if (is_search()) {
    wp_enqueue_style('search-css', $url, [], $version);
}

// Load only when sidebar has content
if (is_active_sidebar('primary')) {
    wp_enqueue_style('sidebar-css', $url, [], $version);
}

// Load only on single posts/pages
if (is_singular()) {
    wp_enqueue_script('single-script', $url, [], $version);
}
```

## Multisite Integration

### Blog Context Awareness

The theme operates across 9 sites with aware context switching:

```php
// Get current blog ID
$current_blog = get_current_blog_id();

// Switch to community site for queries
try {
    switch_to_blog(2); // community.extrachill.com
    $topics = get_posts(['post_type' => 'topic']);
} finally {
    restore_current_blog();
}
```

### Cross-Site Styling

Same CSS/layout system works across all sites:

- extrachill.com (Blog 1): Main site
- community.extrachill.com (Blog 2): Community forums
- shop.extrachill.com (Blog 3): E-commerce
- artist.extrachill.com (Blog 4): Artist platform
- chat.extrachill.com (Blog 5): AI chatbot
- events.extrachill.com (Blog 7): Event calendar
- stream.extrachill.com (Blog 8): Live streaming
- newsletter.extrachill.com (Blog 9): Newsletter
- docs.extrachill.com (Blog 10): Documentation

### Site-Specific Template Variations

Filter hooks allow per-site customization:

```php
add_filter('extrachill_archive_styles', function($styles) {
    if (get_current_blog_id() === 3) {
        // Add WooCommerce-specific styles for shop
        $styles .= 'woocommerce-specific.css';
    }
    return $styles;
});
```

### Network Plugin Integration

The theme integrates with network plugins:

- **extrachill-multisite**: Cross-domain authentication
- **extrachill-users**: User profile URLs, avatar menu
- **extrachill-search**: Multisite search results
- **extrachill-newsletter**: Newsletter subscription forms

All use graceful degradation with `function_exists()` checks.

## Layout Components & Patterns

### Reusable Components

#### Notice System

Three-tier notice system defined in root.css:

```php
// Success notice (green accent border)
echo '<div class="notice notice-success">
    <strong>Success!</strong> Action completed.
</div>';

// Info notice (secondary accent border)
echo '<div class="notice notice-info">
    <strong>Note:</strong> Some information.
</div>';

// Error notice (red error border)
echo '<div class="notice notice-error">
    <strong>Error:</strong> Something went wrong.
</div>';
```

All notices render via `extrachill_notices` action hook.

#### Pagination Component

Native WordPress pagination in `inc/core/templates/pagination.php`:

```php
// Works with WP_Query and custom queries
extrachill_pagination([
    'total' => $wp_query->max_num_pages,
    'current' => get_query_var('paged') ?: 1,
    'label' => 'Posts'
]);
```

#### Share Buttons

Social sharing in `inc/core/templates/share.php`:

```php
extrachill_share_button([
    'share_url' => get_permalink(),
    'share_title' => get_the_title()
]);
```

Supports clipboard copy, Twitter, Facebook, email.

#### Breadcrumbs

Navigation breadcrumbs in `inc/core/templates/breadcrumbs.php`:

```php
extrachill_breadcrumbs([
    'home' => 'Home',
    'separator' => '/'
]);
```

#### Shared Tabs/Accordion

Tab interface for content organization:

```php
// assets/css/shared-tabs.css
// assets/js/shared-tabs.js

// Enqueue shared tabs
extrachill_enqueue_shared_tabs();

// HTML structure uses data-tab attributes
<div class="shared-tabs">
    <button class="tab-button" data-tab="tab-1">Tab 1</button>
    <div id="tab-1" class="tab-panel">Content 1</div>
</div>
```

#### Universal Filter Bar Component

Reusable filter bar for archives and lists:

**Location**: `inc/components/filter-bar.php`, `inc/components/filter-bar-defaults.php`

**Styles**: `assets/css/filter-bar.css`

**Purpose**: Provides consistent filtering UI across archives, forums, and other list views

**Usage**:
```php
// Render filter bar (items registered via filters)
extrachill_filter_bar();
```

**Integration Points**:
- Rendered on archives via `extrachill_archive_above_posts`
- Extended by extrachill-community plugin for forum filtering via `inc/core/filter-bar.php`
- Customizable via filters for plugin-specific options

### Grid & Layout Systems

#### Container Widths

Use CSS variables for responsive layout:

```css
.content-container {
    max-width: var(--container-width);
    margin: 0 auto;
    padding: 0 var(--spacing-md);
}

.single-column {
    max-width: var(--content-width);
    margin: 0 auto;
}

.wide-layout {
    max-width: var(--container-wide);
}
```

#### Responsive Design

Mobile-first breakpoints using CSS media queries:

```css
/* Mobile first (base styles) */
.element {
    width: 100%;
}

/* Tablet and up */
@media (min-width: 768px) {
    .element {
        width: 50%;
    }
}

/* Desktop and up */
@media (min-width: 1024px) {
    .element {
        width: 33.333%;
    }
}
```

## Hooks System for Plugins

### Action Hooks (One-Way Functionality)

Plugins can hook into theme execution points:

```php
// Homepage content
add_action('extrachill_homepage_content', 'my_homepage_block');
add_action('extrachill_after_homepage_content', 'my_cta');

// Notices system
add_action('extrachill_notices', 'my_custom_notice');

// Sidebar areas
add_action('extrachill_sidebar_top', 'my_sidebar_widget');
add_action('extrachill_sidebar_middle', 'my_sidebar_ad');
add_action('extrachill_sidebar_bottom', 'my_sidebar_footer');

// Footer areas
add_action('extrachill_before_footer', 'my_before_footer');
add_action('extrachill_footer_content', 'my_footer_block');
add_action('extrachill_after_footer', 'my_after_footer');

// Search header
add_action('extrachill_search_header', 'my_search_custom');

// Before/after body content
add_action('extrachill_before_body_content', 'my_header_banner');
add_action('extrachill_after_body_content', 'my_footer_banner');
```

### Filter Hooks (Data Transformation)

Plugins modify theme behavior via filters:

```php
// Template overrides
add_filter('extrachill_template_homepage', 'my_custom_homepage');
add_filter('extrachill_template_single_post', 'my_custom_single');
add_filter('extrachill_template_page', 'my_custom_page');
add_filter('extrachill_template_archive', 'my_custom_archive');
add_filter('extrachill_template_search', 'my_custom_search');
add_filter('extrachill_template_404', 'my_custom_404');

// Sidebar replacement
add_filter('extrachill_sidebar_content', 'my_custom_sidebar');

// Enable/disable features
add_filter('extrachill_enable_sticky_header', '__return_false');

// Modify community activity
add_filter('extrachill_community_activity_items', 'my_custom_activity');

// Customize navigation
add_filter('extrachill_navigation_main_menu', 'my_custom_menu');
```

### Hook Execution Map

```
HTTP Request
  ↓
header.php
  ├─ do_action('extrachill_before_body_content')
  └─ Template hooks (based on page type)
      ├─ Homepage: do_action('extrachill_homepage_content')
      ├─ Single: do_action('extrachill_single_content')
      ├─ Archive: do_action('extrachill_archive_content')
      └─ Search: do_action('extrachill_search_content')
  ├─ do_action('extrachill_after_body_content')
footer.php
  ├─ do_action('extrachill_before_footer')
  ├─ do_action('extrachill_footer_content')
  └─ do_action('extrachill_after_footer')
```

## Integration with Network Plugins

### extrachill-multisite Integration

Network plugin provides cross-site functionality:

```php
// Theme uses multisite-aware functions
if (function_exists('ec_get_blog_id')) {
    $community_blog = ec_get_blog_id('community');
}

// Cross-site content access
try {
    switch_to_blog($community_blog);
    $community_data = get_posts([...]);
} finally {
    restore_current_blog();
}
```

### extrachill-users Integration

User management and profiles:

```php
// Get user profile URL (general-purpose, community-first)
if (function_exists('ec_get_user_profile_url')) {
    $profile_url = ec_get_user_profile_url($user_id);
}

// Get author archive URL (article/byline contexts)
if (function_exists('ec_get_user_author_archive_url')) {
    $author_archive_url = ec_get_user_author_archive_url($user_id);
}

// Avatar menu integration
do_action('extrachill_avatar_menu');

// Online users stats
if (function_exists('ec_get_online_users_count')) {
    $online = ec_get_online_users_count();
}
```

### extrachill-search Integration

Multisite search functionality:

```php
// Search results already filtered by search plugin
if (is_search()) {
    // Loop through multisite results
    while (have_posts()) {
        the_post();
        // Display result with site badge
    }
}

// Search header customization
add_action('extrachill_search_header', 'custom_search_info');
```

### extrachill-newsletter Integration

Newsletter subscriptions:

```php
// Subscribe forms available via action hooks
add_action('extrachill_homepage_content', 'my_newsletter_subscribe');

// Subscription form markup
echo '<form class="newsletter-form" method="post">';
echo extrachill_newsletter_subscribe_form();
echo '</form>';
```

## Security & Best Practices

### Output Escaping

Always escape output based on context:

```php
// HTML context
echo esc_html($user_input);

// Attribute context
echo '<div class="' . esc_attr($user_class) . '">';

// URL context
echo '<a href="' . esc_url($user_url) . '">';

// Allow specific HTML tags
echo wp_kses_post($user_html);
```

### Input Sanitization

Sanitize user input with wp_unslash() first:

```php
$title = sanitize_text_field(wp_unslash($_POST['title']));
$content = wp_kses_post(wp_unslash($_POST['content']));
$email = sanitize_email(wp_unslash($_POST['email']));
$url = esc_url_raw(wp_unslash($_POST['url']));
```

### CSRF Protection

Protect AJAX requests with nonces:

```php
// PHP: Generate nonce
$nonce = wp_create_nonce('my_action');

// JavaScript: Include nonce in request
fetch('/wp-json/my-plugin/endpoint', {
    method: 'POST',
    body: JSON.stringify({
        nonce: document.querySelector('[name="nonce"]').value,
        data: myData
    })
});

// PHP: Verify nonce
if (!wp_verify_nonce($_POST['nonce'], 'my_action')) {
    wp_die('Security check failed');
}
```

### Data Validation

Validate all input against expected types:

```php
// Check for required fields
if (empty($title) || empty($content)) {
    wp_die('Missing required fields');
}

// Type check
if (!is_numeric($post_id)) {
    wp_die('Invalid post ID');
}

// Permission check
if (!current_user_can('edit_posts')) {
    wp_die('Insufficient permissions');
}
```

### Conditional Plugin Checks

Always check for plugin functions before use:

```php
// WooCommerce integration
if (function_exists('wc_get_products')) {
    $products = wc_get_products([...]);
}

// bbPress integration
if (function_exists('bbp_get_forum')) {
    $forum = bbp_get_forum($forum_id);
}

// Network plugin functions
if (function_exists('ec_get_user_profile_url')) {
    $profile = ec_get_user_profile_url($user_id);
}
```

## Customization Examples

### Override Styles Properly

Extend styles without using !important:

```css
/* DON'T DO THIS */
.my-element {
    color: red !important; /* Bad practice */
}

/* DO THIS - Use proper CSS specificity */
body .my-element {
    color: red; /* Better specificity */
}

/* OR - Use CSS variables */
.my-element {
    color: var(--accent);
}
```

### Add Custom Styles

Create new stylesheet in `/assets/css/`:

```php
// custom-feature.css
.custom-feature {
    max-width: var(--container-width);
    padding: var(--spacing-lg);
    background: var(--card-background);
    border-radius: var(--border-radius-lg);
}

// Enqueue in functions.php
wp_enqueue_style(
    'custom-feature',
    get_template_directory_uri() . '/assets/css/custom-feature.css',
    ['extrachill-root'],
    filemtime(get_template_directory() . '/assets/css/custom-feature.css')
);
```

### Create Custom Templates

Override theme templates via plugin:

```php
// In plugin file
add_filter('extrachill_template_archive', function($template) {
    return plugin_dir_path(__FILE__) . 'templates/my-archive.php';
});

// my-archive.php has full access to WordPress variables
<?php
if (have_posts()) {
    while (have_posts()) {
        the_post();
        // Custom markup
    }
}
?>
```

### Hook Into Theme Actions

Add content via action hooks:

```php
// In plugin or child theme
add_action('extrachill_homepage_content', 'add_homepage_hero', 5);

function add_homepage_hero() {
    ?>
    <div class="homepage-hero">
        <h1><?php echo esc_html(bloginfo('name')); ?></h1>
        <p><?php echo esc_html(bloginfo('description')); ?></p>
    </div>
    <?php
}
```

### Customize Sidebar Content

Replace or enhance sidebar:

```php
// Full sidebar replacement
add_filter('extrachill_sidebar_content', function($sidebar) {
    ob_start();
    ?>
    <aside class="sidebar">
        <div class="sidebar-widget">
            <!-- Custom widget -->
        </div>
    </aside>
    <?php
    return ob_get_clean();
});

// Add widget to sidebar
add_action('extrachill_sidebar_top', function() {
    echo '<div class="custom-widget">';
    echo 'Custom content';
    echo '</div>';
});
```

### Common Customization Scenarios

**Sticky Header Toggle**:
```php
// Disable sticky header
add_filter('extrachill_enable_sticky_header', '__return_false');
```

**Change Footer Links**:
```php
// Customize footer via filter
add_filter('extrachill_footer_content', function($content) {
    return 'Custom footer content';
});
```

**Modify Archive Title**:
```php
// Hook into archive header
add_action('extrachill_archive_title', function() {
    echo 'Custom archive title';
});
```

**Add Custom Logo**:
```php
// Theme supports custom logo via WordPress
// Appearance > Customize > Site Identity > Logo
```

## Build System & Style Processing

### Style.css Processing

Main `style.css` loads first for core styles:

```php
// functions.php enqueues main stylesheet
wp_enqueue_style('extrachill-style', get_stylesheet_uri(), [], filemtime($css_file));
```

### CSS Organization

CSS files load conditionally based on page context:

1. **root.css** - Always loads (CSS variables, design system)
2. **style.css** - Always loads (core styles)
3. **archive.css** - Loads on archive pages
4. **single-post.css** - Loads on single posts
5. **search.css** - Loads on search results
6. **sidebar.css** - Loads when sidebar active
7. **editor-style.css** - Loads in block editor
8. Other context-specific CSS loads conditionally

### Production Build Process

Create deployable ZIP file:

```bash
# Run build script
./build.sh

# Output: build/extrachill.zip (only)
# Process:
# 1. Removes development files
# 2. Installs production dependencies
# 3. Creates optimized ZIP
# 4. Restores development dependencies
```

### Development Workflow

Work directly with source files:

```bash
# Edit PHP files in /inc/
# Edit CSS in /assets/css/
# Edit JavaScript in /assets/js/

# Check syntax
php -l functions.php

# Enable debugging in wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('SCRIPT_DEBUG', true);
```

---

**Cross-Reference**: For platform-wide architectural patterns, see the root `/AGENTS.md`. For plugin-specific integration patterns, see individual plugin `AGENTS.md` files in `/extrachill-plugins/`.
