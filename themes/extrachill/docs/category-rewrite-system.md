# Category Rewrite System

Forces blank category base for clean URLs in multisite permalink structure.

## Overview

**Location**: `/inc/core/rewrite.php`

The theme removes the `/category/` prefix from category archive URLs, making them appear at the root level.

## URL Structure

**Without Rewrite**:
```
https://extrachill.com/category/news/
https://extrachill.com/category/music-festivals/
```

**With Rewrite**:
```
https://extrachill.com/news/
https://extrachill.com/music-festivals/
```

## Implementation

```php
function extrachill_force_category_base() {
    return '';
}
add_filter( 'pre_option_category_base', 'extrachill_force_category_base' );
add_filter( 'pre_update_option_category_base', 'extrachill_force_category_base' );
```

## How It Works

### Pre-Option Filter

`pre_option_category_base` intercepts WordPress's attempt to retrieve the category base option, returning an empty string instead.

**Effect**: WordPress treats category base as blank when generating URLs

### Pre-Update Filter

`pre_update_option_category_base` prevents updates to the category base setting.

**Effect**: Admin cannot change category base setting (it's always blank)

## Benefits

### Clean URLs

Cleaner, more readable category URLs:
```
/news/article-title/          # Clean
/category/news/article-title/ # Cluttered
```

### SEO

- Shorter URLs
- More keyword-focused
- Better user experience
- Improved readability

### Consistency

Maintains consistent URL structure across multisite network.

## WordPress Permalink Settings

In WordPress Admin > Settings > Permalinks, the "Category base" field has no effect - theme enforces blank category base regardless of setting.

## Hierarchical Categories

Child categories still maintain hierarchy:

```
/music-festivals/              # Parent category
/music-festivals/colorado/     # Child category
```

## Conflict Avoidance

Theme handles potential conflicts between:
- Category slugs
- Page slugs
- Custom post type slugs

**WordPress Priority**: Categories use WordPress's built-in conflict resolution where pages take precedence over categories when slug conflicts occur.

## Multisite Compatibility

Works seamlessly across WordPress multisite network:
- extrachill.com uses clean category URLs
- community.extrachill.com (if categories used) uses same structure

## Permalink Flushing

After activating theme or changing category slugs, flush permalinks:

**Admin Method**:
1. Go to Settings > Permalinks
2. Click "Save Changes" (no changes needed)

**Code Method**:
```php
flush_rewrite_rules();
```

**WP-CLI Method**:
```bash
wp rewrite flush
```

## Category Base in Templates

When linking to categories, use WordPress functions (they automatically generate correct URLs):

```php
// Correct - uses theme rewrite rules
$category_link = get_category_link( $category_id );

// Correct - uses theme rewrite rules
$category_url = get_term_link( $category_term );
```

## Archive Links

Archive links automatically use clean URLs:

```php
// Generates: /news/
echo get_category_link( $news_category_id );

// Generates: /music-festivals/colorado/
echo get_term_link( $colorado_term, 'category' );
```

## Breadcrumb Integration

Breadcrumb system respects clean URLs:

```php
// Breadcrumb output
Home › News › Article Title

// Links:
// Home: /
// News: /news/  (not /category/news/)
// Article: /news/article-title/
```

## Technical Details

### Filter Priority

Both filters use default priority (10), ensuring they run before WordPress generates URLs.

### Option Bypass

Filters run on `pre_option_*` which intercepts option retrieval **before** database query, improving performance.

### Update Prevention

`pre_update_option_*` filter prevents database writes for category base setting.

## Limitations

### Custom Category Bases

Cannot use custom category bases like `/topics/` or `/categories/`. Category base is always blank.

**Workaround**: Use custom taxonomies if custom URL structure needed.

### Slug Conflicts

If page slug matches category slug, page takes precedence:

**Example**:
- Page: `/news/`
- Category: `/news/`
- Result: Page displays (WordPress default behavior)

**Solution**: Use unique category slugs or rename conflicting pages

## Testing Clean URLs

After theme activation, test category URLs:

```bash
# Test category archive
curl -I https://extrachill.com/news/

# Should return 200 OK
# Should NOT redirect to /category/news/
```

## Reverting to Default

To restore default `/category/` prefix:

1. Remove filters from `/inc/core/rewrite.php`
2. Flush rewrite rules
3. Category URLs will include `/category/` prefix

**Not Recommended**: Theme designed for clean URLs

## Why Clean Category URLs

**User Experience**: Shorter, cleaner URLs easier to read and share
**SEO Benefits**: More keyword-focused URLs
**Professional**: Cleaner look for content site
**Consistency**: Matches other modern WordPress themes
**Multisite**: Uniform structure across network sites
