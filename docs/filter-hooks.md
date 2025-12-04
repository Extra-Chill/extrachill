# Filter Hooks Reference

Theme filters for customizing functionality, layout, and embeds while keeping the centralized template router and shared components intact.

## Template Routing Filters

All routing filters receive the default template path (often defined in `inc/core/template-router.php`) and must return a fully qualified template path. Filters bypassed for custom page templates, WooCommerce, and bbPress templates.

### `extrachill_template_homepage`
Override the front-page template that normally loads `inc/home/templates/front-page.php`.

**Parameters**: `$template` (string) – Default path to the front-page template.
**Returns**: Template file path.

### `extrachill_template_single_post`
Provide a replacement for the single post template (`inc/single/single-post.php`).

**Parameters**: `$template` (string) – Default single post template path.
**Returns**: Template file path.

### `extrachill_template_page`
Customize the page template used when WordPress does not already load a custom page template via the admin UI.

**Parameters**: `$template` (string) – Default `inc/single/single-page.php` path.
**Returns**: Template file path. This filter never fires when a custom page template slug exists.

### `extrachill_template_archive`
Override the archive template (`inc/archives/archive.php`).

**Parameters**: `$template` (string) – Default archive template path.
**Returns**: Template file path.

### `extrachill_template_search`
Swap in a custom search results template while still allowing the `extrachill-search` network plugin to control the search structure.

**Parameters**: `$template` (string) – Default `inc/archives/search/search.php` path.
**Returns**: Template file path.

### `extrachill_template_404`
Point to a custom 404 template (`inc/core/templates/404.php`).

**Parameters**: `$template` (string) – Default 404 path.
**Returns**: Template file path.

### `extrachill_template_fallback`
Catch-all for unexpected request types; defaults to `inc/core/templates/404.php`.

**Parameters**: `$template` (string) – Default fallback path.
**Returns**: Template file path.

## Layout & Navigation Filters

### `extrachill_enable_sticky_header`
Controls the presence of the `sticky-header` body class and the reading progress script. Toggled in `functions.php` and checked before enqueueing `assets/js/reading-progress.js` in `inc/core/assets.php`.

**Parameters**: `$enabled` (bool) – Default `true`.
**Returns**: Boolean.

### `extrachill_secondary_header_items`
Inject items into the secondary header bar rendered by `inc/header/secondary-header.php` (hooked to `extrachill_after_header` at priority 5). The secondary header only renders when this filter returns at least one item.

**Parameters**: `$items` (array) – Default: `[]`.
**Returns**: Array of navigation items.

**Item structure** (same format used in `inc/header/secondary-header.php`):
- `url` (string, required)
- `label` (string, required)
- `priority` (int, optional, default `10`)
- `rel` (string, optional)

**Example**:
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

### `extrachill_sidebar_content`
Override the default sidebar markup (loaded from `sidebar.php`). Returning `false` keeps the theme’s sidebar and enqueues `assets/css/sidebar.css`; any string or `true` value short-circuits the sidebar rendering and stops the sidebar CSS from enqueuing in `inc/core/assets.php`.

**Parameters**: None – filter is evaluated without arguments.
**Returns**: `false` to retain default sidebar, otherwise truthy sidebar HTML or `true` to skip the default markup.

### `extrachill_footer_bottom_menu_items`
Adjust the legal/policy links rendered by `inc/footer/footer-bottom-menu.php`.

**Parameters**: `$items` (array) – Default array includes Affiliate Disclosure and Privacy Policy links.
**Returns**: Array of footer link items (same structure as secondary header items).

**Item structure**:
- `url` (string, required)
- `label` (string, required)
- `priority` (int, optional, default `10`)
- `rel` (string, optional)

**Example**:
```php
add_filter( 'extrachill_footer_bottom_menu_items', function( $items ) {
    $items[] = array(
        'url'      => '/terms-of-service',
        'label'    => 'Terms of Service',
        'priority' => 15,
    );
    return $items;
} );
```

### `extrachill_back_to_home_label`
Replace the “Back to Extra Chill” copy used by `inc/footer/back-to-home-link.php`’s smart navigation button.

**Parameters**:
- `$label` (string) – Default label text.
- `$url` (string) – URL the button links to (main site home or current site home).
**Returns**: String label text.

### `extrachill_breadcrumbs_root`
Set the root breadcrumb link that defaults to `<a href="{home_url()}">Extra Chill</a>` inside `inc/core/templates/breadcrumbs.php`.

**Parameters**: `$root_link` (string) – Default root HTML.
**Returns**: HTML string representing the root breadcrumb link.

### `extrachill_breadcrumbs_override_trail`
Provide a full breadcrumb trail (overrides the theme’s fallback trail). When non-empty, the theme skips the built-in trail logic and prints the provided markup.

**Parameters**: `$custom_trail` (string) – Default: empty string.
**Returns**: Breadcrumb HTML.

### `extrachill_taxonomy_badges_skip_term`
Skips rendering of specific taxonomy badges in `inc/core/templates/taxonomy-badges.php`, useful to hide placeholder or archive terms.

**Parameters**:
- `$skip_term` (bool) – Default `false`.
- `$term` (WP_Term)
- `$taxonomy` (string)
- `$post_id` (int)
**Returns**: Boolean; return `true` to prevent the badge from rendering.

## Content Filters

### `extrachill_post_meta`
Modify the post meta markup rendered from `inc/core/templates/post-meta.php`.

**Parameters**:
- `$default_meta` (string) – Default HTML string.
- `$post_id` (int)
- `$post_type` (string)
**Returns**: HTML string.

### Related Posts Filters (used together in `inc/single/related-posts.php`)

#### `extrachill_related_posts_taxonomies`
Choose which taxonomies the related-posts query should consider.

**Parameters**:
- `$taxonomies` (array) – Default `['artist', 'venue']`.
- `$post_id` (int)
**Returns**: Array of taxonomy slugs.

#### `extrachill_related_posts_allowed_taxonomies`
Limit which taxonomies can be used when querying related posts.

**Parameters**:
- `$allowed_taxonomies` (array) – Default `['artist', 'venue']`.
- `$post_type` (string)
**Returns**: Array of taxonomy slugs.

#### `extrachill_related_posts_query_args`
Modify the primary WP_Query arguments used to fetch related posts.

**Parameters**:
- `$query_args` (array)
- `$taxonomy` (string)
- `$post_id` (int)
- `$post_type` (string)
**Returns**: Array of query args.

#### `extrachill_related_posts_tax_query`
Alter the taxonomy query inside `extrachill_related_posts()` before it is merged into `$query_args`.

**Parameters**:
- `$tax_query` (array)
- `$taxonomy` (string)
- `$term_id` (int)
- `$post_id` (int)
- `$post_type` (string)
**Returns**: Tax query array.

#### `extrachill_override_related_posts_display`
Prevent the default related-posts block from rendering when truthy.

**Parameters**:
- `$override` (bool) – Default `false`.
- `$taxonomy` (string)
- `$post_id` (int)
**Returns**: Boolean.

## Rewrite & Network Filters

### `pre_option_category_base` / `pre_update_option_category_base`
Forced empty category base for root URLs (`example.com/news/` rather than `/category/news/`) by `inc/core/rewrite.php`’s `extrachill_force_category_base()` helper.

**Parameters**: None (WordPress passes the option name internally).
**Returns**: Empty string.

### `extrachill_multisite_search`
Provided by the `extrachill-multisite` plugin, returns an array of shared search results from every site in the network.

**Parameters**: Depends on the plugin implementation.
**Returns**: Array of search results.

## Embed Filters

### `custom_bandcamp_embed`
Adjust Bandcamp embed markup generated in `inc/core/editor/bandcamp-embeds.php`.

**Parameters**:
- `$embed_code` (string)
- `$matches` (array)
- `$attr` (array)
- `$url` (string)
- `$rawattr` (string)
**Returns**: String of embed HTML.

### `custom_instagram_embed`
Modify Instagram embeds handled in `inc/core/editor/instagram-embeds.php`.

**Parameters**:
- `$embed` (string)
- `$matches` (array)
- `$attr` (array)
- `$url` (string)
- `$rawattr` (string)
**Returns**: String of embed HTML.

## Example Usage

Subclass and document filter footprints before shipping any customizations.

```php
add_filter( 'extrachill_footer_bottom_menu_items', function( $items ) {
    $items[] = array(
        'url'      => home_url( '/shipping-and-returns/' ),
        'label'    => 'Shipping & Returns Policy',
        'priority' => 30,
    );
    return $items;
} );

add_filter( 'extrachill_enable_sticky_header', function( $enabled ) {
    return is_single();
} );
```
