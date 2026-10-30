# Blog ID Resolution and Hardcoded Site IDs

## Overview

The Extra Chill Platform uses a canonical blog ID map (constants + helper functions) for performance and consistency. The network includes 9 active sites (Blog IDs 5–6 and 8 are unused/decommissioned). This document describes the helper functions in `inc/core/blog-ids.php` and the rules for using them.

## Why Centralize Blog IDs?

Blog IDs are fixed infrastructure for this multisite network. Centralizing them in `inc/core/blog-ids.php` provides a single source of truth and avoids ad-hoc numeric IDs throughout the codebase.

## Blog ID Constants

All blog ID constants defined in `inc/core/blog-ids.php`:

```php
define( 'EC_BLOG_ID_MAIN', 1 );           // extrachill.com
define( 'EC_BLOG_ID_COMMUNITY', 2 );      // community.extrachill.com
define( 'EC_BLOG_ID_SHOP', 3 );           // shop.extrachill.com
define( 'EC_BLOG_ID_ARTIST', 4 );         // artist.extrachill.com (+ extrachill.link)
define( 'EC_BLOG_ID_EVENTS', 7 );         // events.extrachill.com
// Blog ID 8 was stream.extrachill.com (decommissioned April 2026)
define( 'EC_BLOG_ID_NEWSLETTER', 9 );     // newsletter.extrachill.com
define( 'EC_BLOG_ID_DOCS', 10 );          // docs.extrachill.com
define( 'EC_BLOG_ID_WIRE', 11 );          // wire.extrachill.com
define( 'EC_BLOG_ID_STUDIO', 12 );        // studio.extrachill.com
```

**Note**: Blog IDs 5–6 are unused (historical artifacts from site deletions). Blog ID 8 was stream.extrachill.com (decommissioned April 2026).

## Using Blog ID Functions

### ec_get_blog_id() - Single Source of Truth

**Purpose**: Get blog ID by logical slug. This is the **preferred method** for runtime blog ID resolution.

**Parameters**: `$key` (string) - Logical site key

**Returns**: `int|null` - Blog ID or null if unknown

**Usage Pattern**:
```php
// RECOMMENDED PATTERN
$blog_id = ec_get_blog_id( 'newsletter' );
if ( $blog_id ) {
    try {
        switch_to_blog( $blog_id );
        // ...
    } finally {
        restore_current_blog();
    }
}
```

**Advantages**:
- Readable, self-documenting code
- Centralized single source of truth
- Easy to update if blog ID ever changes
- Works with functions that might not exist (when paired with `function_exists()`)

**Valid Keys**:
- `'main'` - Main site (Blog ID 1)
- `'community'` - Community hub (Blog ID 2)
- `'shop'` - E-commerce site (Blog ID 3)
- `'artist'` - Artist platform (Blog ID 4)
- `'events'` - Event calendar (Blog ID 7)
- `'newsletter'` - Newsletter site (Blog ID 9)
- `'docs'` - Documentation (Blog ID 10)
- `'wire'` - Wire (Blog ID 11)
- `'studio'` - Studio (Blog ID 12)

### ec_get_blog_ids() - Associative Map

**Purpose**: Get complete map of all blog IDs.

**Returns**: Associative array with slugs as keys, blog IDs as values

**Usage Pattern**:
```php
// Get all sites for iteration
$blogs = ec_get_blog_ids();
foreach ( $blogs as $slug => $blog_id ) {
    // Process all network sites
}
```

### ec_get_blog_slug_by_id() - Reverse Lookup

**Purpose**: Get logical slug from numeric blog ID.

**Parameters**: `$blog_id` (int) - Numeric blog ID

**Returns**: `string|null` - Slug (e.g., 'artist') or null

**Usage Pattern**:
```php
// Get site name from blog ID (useful in hooks)
$slug = ec_get_blog_slug_by_id( get_current_blog_id() );
```

### ec_get_site_url() - Production URLs

**Purpose**: Get production site URL by logical slug.

**Parameters**: `$key` (string) - Logical site slug

**Returns**: `string|null` - Full HTTPS URL or null

**Usage Pattern**:
```php
// Get link to another site
$newsletter_url = ec_get_site_url( 'newsletter' );
```

**Overridable**: Fires `ec_site_url_override` filter for dev environment customization.

## When to Use What

### Use `ec_get_blog_id()` for:

- **ALL** runtime plugin/theme code
- **ALL** `switch_to_blog()` operations
- contexts where dependencies may be inactive (pair with `function_exists()`)

**Standard Pattern**:
```php
if ( function_exists( 'ec_get_blog_id' ) ) {
    $blog_id = ec_get_blog_id( 'newsletter' );
    if ( $blog_id ) {
        try {
            switch_to_blog( $blog_id );
            // ...
        } finally {
            restore_current_blog();
        }
    }
}
```

### Use Constants (EC_BLOG_ID_*) for:

- Cases where a numeric constant is explicitly needed (e.g., direct comparisons in `sunrise.php`).

### Use Numeric Values (Hardcoded) for:

❌ **NEVER** in plugin/theme code - use `ec_get_blog_id()` instead.  
✅ Only in `.github/sunrise.php` (executes before WordPress loads).  
✅ In comments to document which site is which.

**Don't Do This**:
```php
// Bad - hardcoded in plugin/theme code
switch_to_blog( 9 );
```

**Do This Instead**:
```php
// Good - use helper function
if ( $blog_id = ec_get_blog_id( 'newsletter' ) ) {
    switch_to_blog( $blog_id );
}
```

## Cross-Site Data Access Pattern

### Safe Blog Switching

**Required Pattern**: Always use try/finally to restore blog context.

```php
// CORRECT - Always restore even if error occurs
$blog_id = ec_get_blog_id( 'artist' );
if ( $blog_id ) {
    try {
        switch_to_blog( $blog_id );
        // Perform operations in target blog context
    } finally {
        restore_current_blog();
    }
}
```

## Blog ID Knowledge Base

### Complete Network Map

| Site | Domain | Blog ID | Key | Status |
|------|--------|---------|-----|--------|
| Main | extrachill.com | 1 | `main` | Active |
| Community | community.extrachill.com | 2 | `community` | Active |
| Shop | shop.extrachill.com | 3 | `shop` | Active |
| Artist | artist.extrachill.com | 4 | `artist` | Active |
| *Unused* | *N/A* | 5 | *N/A* | Unused |
| *Unused* | *N/A* | 6 | *N/A* | Unused |
| Events | events.extrachill.com | 7 | `events` | Active |
| *Decommissioned* | stream.extrachill.com | 8 | `stream` | Decommissioned (Apr 2026) |
| Newsletter | newsletter.extrachill.com | 9 | `newsletter` | Active |
| Docs | docs.extrachill.com | 10 | `docs` | Active |
| Wire | wire.extrachill.com | 11 | `wire` | Active |
| Studio | studio.extrachill.com | 12 | `studio` | Active |

## Forbidden Patterns

**Do NOT**:
- Hardcode `9` or any blog ID in plugin code (use `ec_get_blog_id()`).
- Hardcode blog ID mapping in plugins (only in `blog-ids.php`).
- Assume blog ID without verification.
- Skip the try/finally pattern in blog switching.
- Use blog IDs from options (blog IDs are infrastructure, not configuration).
