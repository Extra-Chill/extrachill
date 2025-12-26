# Asset Loading System

Conditional CSS/JS loading with `filemtime()` cache busting comes from `/inc/core/assets.php`, which guards every enqueue with `file_exists()` checks and context-aware logic.

## CSS Architecture

| File | Context | Entry Point |
|------|---------|-------------|
| `assets/css/root.css` | All pages | `extrachill_enqueue_root_styles()` (priority 5)
| `style.css` | All pages | `extrachill_modify_default_style()` (priority 20) replaces the default WordPress style
| `assets/css/taxonomy-badges.css` | All pages that render taxonomy badges | `extrachill_enqueue_taxonomy_badges()` (priority 10)
| `assets/css/single-post.css` | Posts, newsletter | `extrachill_enqueue_single_post_styles()` (priority 20)
| `assets/css/archive.css` | Archive, search, and blog archive (`/all`) | `extrachill_enqueue_archive_styles()` (priority 20)
| `assets/css/search.css` | Search results | `extrachill_enqueue_search_styles()` (priority 20)
| `assets/css/sidebar.css` | Sidebar-enabled singular/post templates, 404 pages | `extrachill_enqueue_sidebar_styles()` (priority 20; only when `extrachill_sidebar_content` filter returns `false`)|
| `assets/css/notice.css` | Notice system pages | `extrachill_enqueue_notice_styles()` (priority 20)
| `assets/css/shared-tabs.css` | Shared tab components | Registered via `extrachill_register_shared_tabs()` (priority 5)
| `assets/css/nav.css` | Navigation flyout | `extrachill_enqueue_navigation_assets()`
| `assets/css/editor-style.css` | Block editor | `extrachill_enqueue_admin_styles()` (via `admin_enqueue_scripts` when editing posts)

## JavaScript Architecture

| Script | Context | Entry Point |
|--------|---------|-------------|
| `assets/js/nav-menu.js` | Navigation flyout toggle and search focus | `extrachill_enqueue_navigation_assets()`
| `assets/js/chill-custom.js` | Archive-specific interactions | `extrachill_enqueue_archive_scripts()` when `is_archive()`
| `assets/js/shared-tabs.js` | Shared tab components with desktop/mobile logic | Registered alongside `shared-tabs.css` in `extrachill_register_shared_tabs()`
| `assets/js/view-tracking.js` | View tracking beacon for singular public posts | `extrachill_enqueue_view_tracking()` (only for public, non-preview singulars; skips users who can edit others’ posts)

### Navigation Assets

`extrachill_enqueue_navigation_assets()` loads `nav.css`/`nav-menu.js` on every page and relies on `filemtime()` for versions so deployment changes flush caches without touching `functions.php`.

### Shared Tabs & Sidebar Assets

`extrachill_register_shared_tabs()` leaves the shared tab styles/scripts registered so templates or plugins can enqueue them when needed; the JavaScript manages desktop and accordion behaviors, hash updates, and emits the `sharedTabActivated` event for integrations.

Sidebar assets (including `sidebar.css`) load via `extrachill_enqueue_sidebar_styles()` only when no `extrachill_sidebar_content` override replaces the sidebar, ensuring widgets render styled content without extra overhead.

### View Tracking

`extrachill_enqueue_view_tracking()` bundles `view-tracking.js` and localizes the `ecViewTracking` object with the current `postId` and `rest_url('extrachill/v1/analytics/view')`. The script is skipped for previewing content and logged-in editors to avoid skewing analytics.

### Share Scripts

`extrachill_enqueue_share_scripts()` loads `share.js` for share button functionality, including clipboard copy with fallback support and social sharing interactions.

### Notice Styles

`extrachill_enqueue_notice_styles()` loads `notice.css` when notices are displayed, providing styling for multiple notice types with cookie-based dismissal.

## Admin/Editor Styles

`extrachill_enqueue_admin_styles()` enqueues `root.css` and `editor-style.css` for post editing screens (`post.php`, `post-new.php`), mirroring the frontend design tokens for a consistent editing experience.

## Cache Busting & Priorities

Every style and script uses `filemtime()` on the source file for the version argument, so browsers refresh when files change. The enqueue priorities (5, 10, 20) guarantee root variables load before dependent styles, and the navigation/styles enqueues avoid redundant loading by checking for contextual flags (e.g., `is_archive()`, `is_search()`, post types).

## Performance Benefits

- **Conditional Loading**: CSS/JS only executes when necessary (archive styles on archives, sidebar styles when widgets appear)
- **Dependency Safety**: Root variables come first, ensuring derived styles inherit the correct custom properties
- **Cache Busting**: Files emit new version numbers whenever their modification time changes
- **Minimal Overhead**: Each enqueue guards file existence to avoid 404s
- **Shared Components**: Shared tabs and view tracking scripts are registered centrally so downstream templates can opt in while keeping their behaviors consistent


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


### extrachill_enqueue_single_post_styles()
Loads single post styles.

**Condition**: `is_singular('post')`
**File**: `/assets/css/single-post.css`
**Dependencies**: `extrachill-root`, `extrachill-style`

### extrachill_enqueue_archive_styles()
Loads archive page styles.

**Condition**: `is_archive() || is_search() || get_query_var('extrachill_blog_archive')`
**File**: `/assets/css/archive.css`
**Dependencies**: `extrachill-root`, `extrachill-style`

### extrachill_enqueue_search_styles()
Loads search result styles.

**Condition**: `is_search()`
**File**: `/assets/css/search.css`
**Dependencies**: `extrachill-root`, `extrachill-style`

### extrachill_enqueue_shared_tabs()
Registers shared tab styles and scripts so templates or plugins can enqueue them on demand.

**Files**: `shared-tabs.css`, `shared-tabs.js`
**Dependencies**: `extrachill-root` for CSS, `wp-polyfill` and vanilla JS for scripts (no jQuery)

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
$css_path = get_stylesheet_directory() . '/assets/css/archive.css';
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

The sticky header can be disabled via filter:

```php
// Disable sticky header
add_filter( 'extrachill_enable_sticky_header', '__return_false' );
```

## Asset Directory Structure

```
assets/
  /css/
    root.css              # CSS variables, theme colors
    style.css             # Main stylesheet (loaded via get_stylesheet_uri)
    taxonomy-badges.css   # Taxonomy badge colors
    nav.css               # Navigation styles
    single-post.css       # Single post styles
    archive.css           # Archive page styles
    search.css            # Search result styles
    shared-tabs.css       # Tab interface styles
    share.css             # Social share buttons
    sidebar.css           # Sidebar component styles
    notice.css            # Notice system styles
    editor-style.css      # Block editor styles
  /js/
    nav-menu.js           # Navigation functionality
    chill-custom.js       # Archive interactions
    shared-tabs.js        # Tab interface logic
    share.js              # Share button interactions
  /fonts/
    extrachill.svg        # Icon sprite with QR/download icons
```
