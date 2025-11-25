# Asset Loading System

Conditional CSS/JS loading with automatic cache busting via file modification times.

## CSS Architecture

**Location**: `inc/core/assets.php`

The theme uses 9 modular CSS files loaded conditionally based on page context:

| File | Context | Dependencies | Priority |
|------|---------|--------------|----------|
| `root.css` | All pages | None | 5 |
| `style.css` | All pages | root.css | 20 |
| `badge-colors.css` | All pages | root.css | 10 |
| `nav.css` | All pages | None | Default |
| `home.css` | Homepage only | None | Default |
| `single-post.css` | Single posts | root.css, style.css | 20 |
| `archive.css` | Archives/Search | root.css, style.css | 20 |
| `search.css` | Search results | root.css, style.css | 20 |
| `shared-tabs.css` | Pages only | None | Default |
| `editor-style.css` | Block editor | root.css | Admin only |

## Cache Busting

All assets use `filemtime()` for automatic version numbering:

```php
wp_enqueue_style(
    'extrachill-root',
    get_stylesheet_directory_uri() . '/assets/css/root.css',
    array(),
    filemtime( get_stylesheet_directory() . '/assets/css/root.css' )
);
```

**Benefit**: Browser cache automatically invalidates when files change.

## CSS Loading Functions

### extrachill_enqueue_root_styles()
Loads root CSS variables and theme colors.

**Priority**: 5 (loads first)
**Dependencies**: None
**Hook**: `wp_enqueue_scripts`

### extrachill_enqueue_main_styles()
Loads badge color styles.

**Priority**: 10
**Dependencies**: `extrachill-root`
**Hook**: `wp_enqueue_scripts`

### extrachill_modify_default_style()
Loads main theme stylesheet.

**Priority**: 20 (loads after root)
**Dependencies**: `extrachill-root`
**Hook**: `wp_enqueue_scripts`

### extrachill_enqueue_home_styles()
Loads homepage-specific styles.

**Condition**: `is_front_page()`
**File**: `/assets/css/home.css`

### extrachill_enqueue_single_post_styles()
Loads single post styles.

**Condition**: `is_singular('post')`
**File**: `/assets/css/single-post.css`
**Dependencies**: `extrachill-root`, `extrachill-style`

### extrachill_enqueue_archive_styles()
Loads archive page styles.

**Condition**: `is_archive() || is_search() || is_page_template('page-templates/all-posts.php')`
**File**: `/assets/css/archive.css`
**Dependencies**: `extrachill-root`, `extrachill-style`

### extrachill_enqueue_search_styles()
Loads search result styles.

**Condition**: `is_search()`
**File**: `/assets/css/search.css`
**Dependencies**: `extrachill-root`, `extrachill-style`

### extrachill_enqueue_shared_tabs()
Loads tab interface styles and scripts.

**Condition**: `is_page()`
**Files**: `shared-tabs.css`, `shared-tabs.js`
**Dependencies**: jQuery (for JS)

## JavaScript Loading

### Navigation Scripts

```php
function extrachill_enqueue_navigation_assets() {
    wp_enqueue_style( 'extrachill-nav-styles', ... );
    wp_enqueue_script( 'extrachill-nav-menu', ... );
}
```

**Files**: `nav.css`, `nav-menu.js`
**Context**: All pages
**Load Position**: Footer (`true` parameter)

### Archive Scripts

```php
function extrachill_enqueue_archive_scripts() {
    if ( is_archive() ) {
        wp_enqueue_script( 'wp-innovator-custom-script', ... );
    }
}
```

**File**: `chill-custom.js`
**Context**: Archive pages only

### Reading Progress Script

```php
function extrachill_enqueue_reading_progress() {
    if ( ! apply_filters( 'extrachill_enable_sticky_header', true ) ) {
        return;
    }
    wp_enqueue_script( 'reading-progress-script', ... );
}
```

**File**: `reading-progress.js`
**Context**: All pages (unless sticky header disabled)
**Filter**: `extrachill_enable_sticky_header`

## Admin/Editor Styles

```php
function extrachill_enqueue_admin_styles($hook) {
    if ($hook == 'post.php' || $hook == 'post-new.php') {
        wp_enqueue_style( 'extrachill-admin-root', ... );
        wp_enqueue_style( 'extrachill-admin-editor', ... );
    }
}
```

**Hook**: `admin_enqueue_scripts`
**Files**: `root.css`, `editor-style.css`
**Context**: Post editor pages only

## Loading Priority

1. **Priority 5**: Root CSS variables
2. **Priority 10**: Badge colors
3. **Priority 20**: Main stylesheet, page-specific CSS
4. **Default**: Navigation, utility styles

## File Existence Checks

All enqueue functions check file existence before loading:

```php
$css_path = get_stylesheet_directory() . '/assets/css/home.css';
if ( file_exists( $css_path ) ) {
    wp_enqueue_style( ... );
}
```

## Performance Benefits

**Conditional Loading**: CSS/JS only loads when needed
**Dependency Management**: Proper load order ensures root variables available
**Cache Busting**: Automatic version updates on file changes
**Minimal Overhead**: File existence checks prevent 404 errors

## Disabling Sticky Header

Affects reading progress script loading:

```php
// Disable sticky header
add_filter( 'extrachill_enable_sticky_header', '__return_false' );
```

## Asset Directory Structure

```
/assets/
  /css/
    root.css              # CSS variables, theme colors
    style.css             # Main stylesheet (loaded via get_stylesheet_uri)
    badge-colors.css      # Taxonomy badge colors
    nav.css               # Navigation styles
    home.css              # Homepage styles
    single-post.css       # Single post styles
    archive.css           # Archive page styles
    search.css            # Search result styles
    shared-tabs.css       # Tab interface styles
    editor-style.css      # Block editor styles
  /js/
    nav-menu.js           # Navigation functionality
    reading-progress.js   # Sticky header progress
    chill-custom.js       # Archive interactions
    shared-tabs.js        # Tab interface logic
  /fonts/
    fontawesome.svg       # Icon sprite (with cache busting)
```
