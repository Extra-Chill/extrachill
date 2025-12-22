# ExtraChill SEO Plugin - Architecture Plan

## Overview

A lean, network-activated SEO plugin replacing traditional SEO plugins. Manages meta tags, structured data, Open Graph, Twitter Cards, and robots directives across all Extra Chill network sites.

**Version**: 0.3.0  
**Network**: true (required)  
**Replaces**: Yoast SEO (already removed)

## Design Principles

1. **Code-first defaults** - SEO rules defined in PHP; settings only for required external integrations
2. **Single source of truth** - One network-wide configuration path (site options)
3. **WordPress native patterns** - Use core filters (`wp_robots`, `document_title_parts`, `wp_head`)
4. **Minimal footprint** - No custom tables, no admin UI bloat, no premium upsells
5. **Follow Google's guidance** - Index pagination, unique canonicals per page, noindex only where appropriate

## Industry Research

Based on analysis of Google's official documentation and major publishers (Pitchfork, The Verge):

- **Pagination**: Index all paginated pages with unique canonicals (industry standard)
- **Title tags**: Include context (e.g., "Blog - Page 30 - Extra Chill" not "Page 30 - Extra Chill")
- **Robots**: Noindex search results and date archives; keep author archives indexed
- **Structured data**: WebSite schema with SearchAction, Organization with sameAs

---

## Phase 1 Scope (Core SEO Replacement)

### Current Status

- **IndexNow** support is implemented (ping-only):
  - Network setting: IndexNow key (site option)
  - URL pings on publish/unpublish/delete
  - Requirement: host a static `/{key}.txt` file at each domain root
- **Network admin page** is implemented under the existing `extrachill-multisite` menu (`SEO` submenu).
- **Default OG image** is implemented as a network setting with media picker UI.

### Next Enhancements

- Add optional batching / queueing for bulk updates.
- Future enhancement: **dynamic OG image generation** (auto-generated 16:9 social cards).
- Consider: network sitemap index (links all site sitemaps).

### Meta Tags

| Tag | Source | Notes |
|-----|--------|-------|
| Title | `document_title_parts` filter | Site-specific patterns, separator: ` - ` |
| Description | Post excerpt or auto-generated | First 160 chars of content as fallback |
| Canonical | WordPress native | One canonical per page, no cross-page canonicalization |
| Robots | `wp_robots` filter | Noindex: search results, date archives, sparse taxonomy terms |

### Open Graph

| Property | Value |
|----------|-------|
| og:title | Same as title tag |
| og:description | Same as meta description |
| og:url | Canonical URL |
| og:site_name | "Extra Chill" |
| og:image | Featured image or site default |
| og:type | "website" (archives) / "article" (posts) |
| og:locale | "en_US" |

### Twitter Cards

| Property | Value |
|----------|-------|
| twitter:card | summary_large_image |
| twitter:site | @extra_chill |
| twitter:title | Same as og:title |
| twitter:description | Same as og:description |
| twitter:image | Same as og:image |

### JSON-LD Structured Data

All schemas output as single `<script type="application/ld+json">` with `@graph` array.

#### WebSite Schema (all pages)
```json
{
  "@type": "WebSite",
  "@id": "https://extrachill.com/#website",
  "url": "https://extrachill.com/",
  "name": "Extra Chill",
  "description": "Online Music Scene",
  "potentialAction": {
    "@type": "SearchAction",
    "target": {
      "@type": "EntryPoint",
      "urlTemplate": "https://extrachill.com/?s={search_term_string}"
    },
    "query-input": "required name=search_term_string"
  },
  "inLanguage": "en-US"
}
```

#### Organization Schema (all pages)
```json
{
  "@type": "Organization",
  "@id": "https://extrachill.com/#organization",
  "name": "Extra Chill",
  "url": "https://extrachill.com",
  "logo": {
    "@type": "ImageObject",
    "url": "https://extrachill.com/wp-content/uploads/logo.png"
  },
  "description": "Online Music Scene",
  "foundingDate": "2011",
  "founder": {
    "@type": "Person",
    "name": "Chris Huber"
  },
  "sameAs": [
    "https://facebook.com/extrachill",
    "https://twitter.com/extra_chill",
    "https://instagram.com/extrachill",
    "https://youtube.com/@extra-chill",
    "https://pinterest.com/extrachill",
    "https://github.com/Extra-Chill"
  ]
}
```

#### Article Schema (singular posts)
```json
{
  "@type": "Article",
  "@id": "https://extrachill.com/2025/01/post-slug/#article",
  "headline": "Post Title",
  "datePublished": "2025-01-15T12:00:00+00:00",
  "dateModified": "2025-01-16T14:30:00+00:00",
  "author": {
    "@type": "Person",
    "name": "Author Name",
    "url": "https://extrachill.com/author/username/"
  },
  "publisher": {
    "@id": "https://extrachill.com/#organization"
  },
  "isPartOf": {
    "@id": "https://extrachill.com/#website"
  },
  "image": "https://extrachill.com/wp-content/uploads/featured.jpg",
  "inLanguage": "en-US"
}
```

#### BreadcrumbList Schema (non-homepage)
```json
{
  "@type": "BreadcrumbList",
  "@id": "https://extrachill.com/category/interviews/#breadcrumb",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Home",
      "item": "https://extrachill.com/"
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "Interviews"
    }
  ]
}
```

#### WebPage Schema (archives/pages)
```json
{
  "@type": "CollectionPage",
  "@id": "https://extrachill.com/blog/#webpage",
  "url": "https://extrachill.com/blog/",
  "name": "Blog - Extra Chill",
  "isPartOf": {
    "@id": "https://extrachill.com/#website"
  },
  "breadcrumb": {
    "@id": "https://extrachill.com/blog/#breadcrumb"
  },
  "inLanguage": "en-US"
}
```

### Robots Logic

| Context | Directive | Rationale |
|---------|-----------|-----------|
| Posts/Pages | index, follow | Standard content |
| Archives (category, tag, author) | index, follow | Valuable navigation |
| Pagination (all pages) | index, follow | Google recommendation |
| Search results | noindex, follow | No search value |
| Date archives | noindex, follow | Thin/duplicate archives |
| Sparse taxonomy terms (<2 posts) | noindex, follow | Avoid thin term archives across all taxonomies |

---

## File Structure

```
extrachill-plugins/extrachill-seo/
├── extrachill-seo.php              # Main plugin file (Network: true)
├── inc/
│   ├── core/
│   │   ├── settings.php            # Network settings (site options)
│   │   ├── indexnow.php            # IndexNow pings
│   │   ├── meta-tags.php           # Title, description output
│   │   ├── canonical.php           # Canonical URL handling
│   │   ├── robots.php              # Robots meta logic
│   │   ├── open-graph.php          # OG tags output
│   │   └── twitter-cards.php       # Twitter Card output
│   ├── admin/
│   │   ├── network-settings.php    # Network settings page
│   │   └── media-picker.js         # Default OG media picker
│   └── schema/
│       ├── schema-output.php       # JSON-LD output handler (@graph)
│       ├── schema-website.php      # WebSite schema
│       ├── schema-organization.php # Organization schema
│       ├── schema-article.php      # Article schema
│       ├── schema-breadcrumb.php   # BreadcrumbList schema
│       └── schema-webpage.php      # WebPage/CollectionPage
├── composer.json
├── .buildignore
├── build.sh -> ../../.github/build.sh
├── AGENTS.md
├── PLAN.md
└── README.md
```

---

## Hook Architecture

### Output Priority (wp_head)

| Priority | Hook/Filter | Purpose |
|----------|-------------|---------|
| 1 | `pre_get_document_title` | Title tag generation |
| 1 | `document_title_parts` | Title parts modification |
| 1 | `document_title_separator` | Separator: ` - ` |
| 5 | `wp_head` | Meta description, canonical, robots |
| 5 | `wp_head` | Open Graph tags |
| 5 | `wp_head` | Twitter Card tags |
| 10 | `wp_head` | JSON-LD structured data |
| 10 | `wp_robots` | Robots directive logic |


## Site-Specific Title Patterns

| Site | Pattern | Example |
|------|---------|---------|
| extrachill.com | `{Title} - Extra Chill` | "Interview with Artist - Extra Chill" |
| extrachill.com (blog archive) | `Blog - Page {n} - Extra Chill` | "Blog - Page 3 - Extra Chill" |
| community.extrachill.com | `{Title} - Extra Chill Community` | "Music Discussion - Extra Chill Community" |
| shop.extrachill.com | `{Title} - Extra Chill Shop` | "T-Shirt - Extra Chill Shop" |
| artist.extrachill.com | `{Title} - Extra Chill Artists` | "Artist Name - Extra Chill Artists" |
| events.extrachill.com | `{Title} - Extra Chill Events` | "SXSW 2025 - Extra Chill Events" |
| chat.extrachill.com | `{Title} - Extra Chill Chat` | "Chat - Extra Chill Chat" |
| stream.extrachill.com | `{Title} - Extra Chill Stream` | "Live - Extra Chill Stream" |
| newsletter.extrachill.com | `{Title} - Extra Chill Newsletter` | "Subscribe - Extra Chill Newsletter" |
| docs.extrachill.com | `{Title} - Extra Chill Docs` | "Getting Started - Extra Chill Docs" |

Site suffix determined by `get_current_blog_id()` mapped via `ec_get_blog_slug_by_id()`.

---

## Data Sources

### Organization Data (Hardcoded)

```php
function ec_seo_get_organization_data() {
    return [
        'name'         => 'Extra Chill',
        'url'          => 'https://extrachill.com',
        'logo'         => 'https://extrachill.com/wp-content/uploads/2024/07/cropped-bigger-logo-black-1-400x400.jpeg',
        'description'  => 'Online Music Scene',
        'founding_date' => '2011',
        'founder'      => 'Chris Huber',
        'same_as'      => [
            'https://facebook.com/extrachill',
            'https://twitter.com/extra_chill',
            'https://instagram.com/extrachill',
            'https://youtube.com/@extra-chill',
            'https://pinterest.com/extrachill',
            'https://github.com/Extra-Chill',
        ],
    ];
}
```

### Social Profiles

Sourced from `extrachill/inc/core/templates/social-links.php` - already hardcoded there.

---

## XML Sitemap Strategy

**Use WordPress native sitemap** (`/wp-sitemap.xml`) with minimal customization:

```php
// Remove users from sitemap (privacy)
add_filter('wp_sitemaps_add_provider', function($provider, $name) {
    return $name === 'users' ? false : $provider;
}, 10, 2);
```

Yoast sitemap (`/sitemap_index.xml`) is no longer used.

---

## Migration Steps


### Deployment Checklist
1. Network-activate extrachill-seo
2. Verify output on key pages (homepage, single post, archive, author)
3. Ensure WordPress native sitemap is accessible at `/wp-sitemap.xml`
4. Submit `/wp-sitemap.xml` in Search Console (per-domain)

### Post-Deployment
1. Monitor Search Console for crawl errors
2. Verify structured data with Google Rich Results Test

---

## Phase 2 (Future)

- Internal link counter (inbound/outbound per post)
- Admin columns showing link counts
- Network-wide sitemap index linking all site sitemaps
- AI-powered alt text generator
- AI-powered meta description generator

---

## Testing Checklist

- [ ] Title tags render correctly on all page types
- [ ] Meta descriptions auto-generate from content
- [ ] Canonical URLs are unique per page
- [ ] Open Graph tags validate (Facebook Debugger)
- [ ] Twitter Cards validate (Twitter Card Validator)
- [ ] JSON-LD validates (Google Rich Results Test)
- [ ] Search results pages are noindexed
- [ ] Date archives are noindexed
- [ ] Author archives remain indexed
- [ ] Pagination remains indexed with unique canonicals
- [ ] WordPress native sitemap accessible at /wp-sitemap.xml

---

## References

- [Google: Pagination Best Practices](https://developers.google.com/search/docs/specialty/ecommerce/pagination-and-incremental-page-loading)
- [Google: Sitelinks](https://developers.google.com/search/docs/appearance/sitelinks)
- [Schema.org: Organization](https://schema.org/Organization)
- [Schema.org: Article](https://schema.org/Article)
- [Schema.org: WebSite](https://schema.org/WebSite)
