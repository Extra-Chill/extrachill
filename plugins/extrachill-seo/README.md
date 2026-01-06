# Extra Chill SEO

## Version 0.5.3

Lean SEO plugin for the Extra Chill Platform, replacing Yoast SEO with code-first meta tags, structured data, and robots directives.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- WordPress Multisite

## Installation

1. Network-activate this plugin
2. Verify SEO output on key pages
3. Network-deactivate Yoast SEO
4. Update Search Console sitemap to `/wp-sitemap.xml`

## Features

- **Meta Tags**: Title, description, canonical
- **Open Graph**: Facebook, LinkedIn sharing
- **Twitter Cards**: X/Twitter sharing
- **Structured Data**: WebSite, Organization, Article, BreadcrumbList, WebPage schemas
- **Robots**: Smart noindex for search results and date archives

## Configuration

SEO rules are code-defined (based on WordPress page context) with a small set of network settings for required integrations.

### Network Settings

In Network Admin, open the SEO settings page under the existing Extra Chill multisite menu:

- **Default OG Image**: Fallback `og:image` when a post has no featured image
- **IndexNow Key**: When set, the plugin pings IndexNow on publish/unpublish/delete and on published post updates. You must also host `/{key}.txt` as a static file at the domain root.

## License

GPL v2 or later
