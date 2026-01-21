# Extra Chill SEO Plugin

Network-activated SEO plugin replacing Yoast SEO with a lean, code-first approach.

## Purpose

Manages meta tags, structured data, robots directives, and social sharing across all Extra Chill network sites without database bloat or admin UI complexity.

## Architecture

### Core Components (`inc/core/`)

- **settings.php** - Network settings stored via site options (IndexNow key, default OG image)
- **indexnow.php** - IndexNow key endpoint and publish/trash URL pings
- **meta-tags.php** - Title tag modification via `document_title_parts` filter, meta description generation from excerpt/content
- **robots.php** - Robots directives via `wp_robots` filter (noindex: search, date archives, sparse taxonomy terms)
- **open-graph.php** - OG tags for Facebook/LinkedIn sharing (uses network default OG image)
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

Titles use site-specific suffixes based on the canonical multisite helpers (via `ec_get_blog_slug_by_id()`):

| Site Slug | Suffix |
|---------|--------|
| main | Extra Chill |
| community | Extra Chill Community |
| shop | Extra Chill Shop |
| artist | Extra Chill Artist Platform |
| chat | Extra Chill Chat |
| events | Extra Chill Events |
| stream | Extra Chill Stream |
| newsletter | Extra Chill Newsletter |
| docs | Extra Chill Docs |
| horoscope | Extra Chill Horoscope |

## Robots Logic

| Context | Directive |
|---------|-----------|
| Posts/Pages | index, follow |
| Archives | index, follow |
| Pagination | index, follow |
| Search results | noindex, follow |
| Date archives | noindex, follow |
| Sparse taxonomy terms (<2 posts) | noindex, follow |
| bbPress user profile | index, follow |
| bbPress user subpages (replies, topics, etc.) | noindex, follow |

bbPress user subpages also canonicalize to the main user profile to consolidate link equity.

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

- Yoast SEO
