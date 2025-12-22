# Extra Chill SEO Plugin

Network-activated SEO plugin replacing Yoast SEO with a lean, code-first approach.

## Purpose

Manages meta tags, structured data, robots directives, and social sharing across all Extra Chill network sites without database bloat or admin UI complexity.

## Architecture

### Core Components (`inc/core/`)

- **meta-tags.php** - Title tag modification via `document_title_parts` filter, meta description generation from excerpt/content
- **robots.php** - Robots directives via `wp_robots` filter (noindex: search, date archives, sparse taxonomy terms)
- **open-graph.php** - OG tags for Facebook/LinkedIn sharing
- **twitter-cards.php** - Twitter Card tags (summary_large_image)

### Schema Components (`inc/schema/`)

All schemas output as single JSON-LD `@graph` via `extrachill_seo_schema_graph` filter:

- **schema-output.php** - Consolidates graph, outputs JSON-LD, provides org data
- **schema-website.php** - WebSite schema with SearchAction
- **schema-organization.php** - Organization schema with sameAs social links
- **schema-article.php** - Article schema for singular posts
- **schema-breadcrumb.php** - BreadcrumbList for non-homepage
- **schema-webpage.php** - WebPage/CollectionPage for archives

## Site-Specific Title Patterns

Titles use site-specific suffixes based on `get_current_blog_id()`:

| Blog ID | Suffix |
|---------|--------|
| 1 | Extra Chill |
| 2 | Extra Chill Community |
| 3 | Extra Chill Shop |
| 4 | Extra Chill Artists |
| 5 | Extra Chill Chat |
| 7 | Extra Chill Events |
| 8 | Extra Chill Stream |
| 9 | Extra Chill Newsletter |
| 10 | Extra Chill Docs |

## Robots Logic

| Context | Directive |
|---------|-----------|
| Posts/Pages | index, follow |
| Archives | index, follow |
| Pagination | index, follow |
| Search results | noindex, follow |
| Date archives | noindex, follow |
| Sparse taxonomy terms (<2 posts) | noindex, follow |

## Extension Points

### Adding Schema Types

```php
add_filter('extrachill_seo_schema_graph', function($graph) {
    $graph[] = [
        '@type' => 'Event',
        'name'  => 'Custom Event',
        // ...
    ];
    return $graph;
});
```

### Modifying Descriptions

Override `ec_seo_get_meta_description()` behavior by filtering earlier or using post excerpt.

## Dependencies

- WordPress 6.0+
- PHP 8.1+
- Multisite enabled (required)

## Replaces

- Yoast SEO (network deactivation required)
- Yoast SEO (plugin replacement)
