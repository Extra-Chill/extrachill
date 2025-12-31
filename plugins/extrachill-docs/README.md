# Extra Chill Docs

A dedicated documentation hub for the Extra Chill Platform deployed on docs.extrachill.com (Blog ID 10). Provides a clean, navigable platform-based documentation experience using custom post types and taxonomies.

## Overview

Extra Chill Docs powers the documentation hub at **docs.extrachill.com** with:
- **Platform-based organization** - Documentation organized by feature (Artist Platform, Community, Events, etc.)
- **Clean URL structure** - `/platform-slug/doc-slug/` format for intuitive navigation
- **Dynamic homepage** - Platform cards display only when documentation exists
- **REST API** - Public endpoint for documentation metadata and platform info

## Features

### 📚 Documentation Organization
- **Custom Post Type** (`ec_doc`) - Documentation articles with hierarchical support
- **Platform Taxonomy** (`ec_doc_platform`) - Organize docs by network site/feature
- **Homepage Cards** - Visual platform navigation showing available documentation
- **Archive Pages** - Browse all docs for a specific platform

### 🔍 Discovery & Search
- **Platform Navigation** - Browse docs by feature or product
- **REST API Endpoint** - `GET /wp-json/extrachill/v1/docs-info` returns metadata + platform list
- **Network Search Integration** - Integrated with extrachill-search for multisite discovery
- **Breadcrumb Navigation** - Clear navigation path with network dropdown

### 🎨 Design System
- **Theme Integration** - Uses extrachill theme CSS variables and design system
- **Responsive Cards** - Mobile-friendly platform cards with grid layout
- **Conditional Display** - Platforms without docs don't appear on homepage
- **Theme Templates** - Archive and single templates handled by extrachill theme

## Installation

### Requirements
- WordPress 5.0+
- PHP 7.4+
- Extra Chill Theme (for styling and templates)
- Activated on docs.extrachill.com site (Blog ID 10)

### Setup

1. **Upload the plugin**:
   ```bash
   # Extract build/extrachill-docs.zip to wp-content/plugins/
   unzip build/extrachill-docs.zip -d wp-content/plugins/
   ```

2. **Activate on Blog ID 10 only**:
   - Go to Network Admin → Sites → docs.extrachill.com
   - Click "Plugins"
   - Activate "Extra Chill Docs"

3. **Verify platform seeding**:
   - Go to Documentation → Platforms
   - Should see 8 default platforms (Artist Platform, Community, Events, Stream, Newsletter, Shop, Chat, Horoscopes)

4. **Add documentation**:
   - Go to Documentation → Add New
   - Write content targeting non-technical users
   - Assign to relevant Platform taxonomy
   - Publish

## Usage

### Creating Documentation

1. **Navigate to Documentation**
   - WordPress Admin → Documentation → Add New

2. **Write Content**
   - Use Gutenberg blocks for rich formatting
   - Include images, videos, step-by-step guides
   - Target non-technical users

3. **Organize by Platform**
   - Select relevant platform from "Platforms" sidebar
   - Only one platform per doc recommended
   - Platforms without docs don't display on homepage

4. **Publish**
   - Set post status to "Published"
   - URL automatically formats as `/platform-slug/doc-slug/`

### Homepage Display

The homepage displays platform cards for all platforms that have published documentation. Each card shows:
- **Platform Icon/Name** - Visual platform identifier
- **Description** - Platform purpose
- **Doc Count** - Number of published docs
- **Link** - Navigate to platform archive

### Platform Archive

Each platform archive (`/artist-platform/`, `/community/`, etc.) displays all docs for that platform with:
- **Title** - Doc name
- **Excerpt** - Brief description
- **Meta** - Publication date, author
- **Link** - Navigate to full doc

## REST API

### Documentation Info Endpoint

**Endpoint**: `GET /wp-json/extrachill/v1/docs-info`

**Response**:
```json
{
  "platforms": [
    {
      "id": 1,
      "name": "Artist Platform",
      "slug": "artist-platform",
      "description": "Artist profiles, link pages, analytics...",
      "doc_count": 5
    }
  ],
  "about": {
    "title": "About Extra Chill",
    "content": "HTML content from main site About page",
    "url": "https://extrachill.com/about/"
  }
}
```

**Details**:
- Returns all platforms with published documentation
- Includes main site About page content (Blog 1)
- Public endpoint - no authentication required
- Useful for web/mobile apps and external integrations

## Development

### File Structure

```
extrachill-docs/
├── extrachill-docs.php              # Main plugin file
├── README.md                         # This file
├── AGENTS.md                         # Technical documentation
├── inc/
│   ├── core/
│   │   ├── post-types.php           # ec_doc registration
│   │   ├── taxonomies.php           # ec_doc_platform + seeding
│   │   └── assets.php                # CSS enqueuing
│   └── home/
│       └── homepage-cards.php        # Homepage cards block
└── assets/
    └── css/
        └── docs.css                  # Card grid styles
```

### Key Functions

**Post Type Registration** (`post-types.php`):
```php
// Register ec_doc custom post type
register_post_type('ec_doc', [...]);
```

**Platform Seeding** (`taxonomies.php`):
```php
// Create default platforms on activation
extrachill_docs_seed_platforms();
```

**Homepage Cards** (`homepage-cards.php`):
```php
// Display platform cards on homepage
add_action('extrachill_homepage_content', 'extrachill_docs_homepage_cards');
```

**REST Endpoint** (main file):
```php
// Register documentation metadata endpoint
register_rest_route('extrachill/v1', '/docs-info', [...]
```

### Conditional Display

Platforms without published documentation are automatically hidden:

```php
// Query only platforms with published docs
$platforms = get_terms([
    'taxonomy' => 'ec_doc_platform',
    'hide_empty' => true  // Only platforms with posts
]);
```

### Theme Integration

The plugin relies on extrachill theme for:
- **CSS Variables** - Design tokens from root.css
- **Archive Template** - Theme template for doc archives
- **Single Template** - Theme template for single docs
- **Breadcrumbs** - Theme breadcrumb system
- **Navigation** - Network dropdown in header

## Architecture

### Custom Post Type: ec_doc

- **Hierarchical**: Supports parent/child relationships
- **Supports**: Title, editor, excerpt, custom-fields, thumbnail
- **Permalinks**: Uses platform taxonomy in URL slug
- **UI**: Dedicated "Documentation" admin menu

### Custom Taxonomy: ec_doc_platform

- **Hierarchical**: Supports nested platforms
- **Terms**: 8 default platforms (seeded on activation)
- **Filtering**: Archive pages filtered by platform
- **Display**: Platform cards on homepage show only active platforms

### Homepage Integration

Plugin hooks into `extrachill_homepage_content` action to render platform cards instead of default homepage content:

```php
add_action('extrachill_homepage_content', function() {
    // Render platform cards
    echo extrachill_docs_get_platform_cards();
});
```

### Security

- **Capability Checks**: Uses WordPress default post/taxonomy capabilities
- **Escaping**: All output properly escaped
- **Sanitization**: All input sanitized via WordPress functions
- **REST API**: Public endpoint, no sensitive data exposed

## Content Guidelines

**For Documentation Writers**:

1. **Write for End Users**
   - Avoid technical jargon
   - Explain what, why, and how
   - Use non-technical language

2. **Use Visual Walkthroughs**
   - Include screenshots
   - Add videos if helpful
   - Annotate steps clearly

3. **Step-by-Step Format**
   - Number each step
   - Use bold for buttons/menus
   - Include expected results

4. **Organize Logically**
   - One feature per doc
   - Build on previous knowledge
   - Cross-link related topics

5. **Keep Updated**
   - Review regularly
   - Update when features change
   - Remove outdated information

## Troubleshooting

### Platforms Not Showing on Homepage

**Problem**: Platform cards not displaying on docs homepage.

**Solutions**:
1. Ensure plugin is activated on Blog ID 10 (docs.extrachill.com)
2. Verify platforms are seeded:
   - Admin → Documentation → Platforms
   - Should show 8 default platforms
3. Add published documentation:
   - Create a new doc
   - Assign to a platform
   - Publish
4. Check theme is active and extrachill-docs is activated

### REST Endpoint Returns Empty

**Problem**: `/wp-json/extrachill/v1/docs-info` returns empty platforms.

**Solutions**:
1. Verify published docs exist:
   - Admin → Documentation → All Documentation
   - Filter by status "Published"
2. Ensure platforms are assigned:
   - Each doc should have a platform taxonomy term
3. Check permalinks:
   - Settings → Permalinks → Save (flush rewrite rules)
4. Verify site is multisite:
   - Plugin requires WordPress multisite network

### CSS Not Loading Properly

**Problem**: Homepage cards don't display correctly.

**Solutions**:
1. Verify extrachill theme is active
2. Check CSS is enqueuing:
   - Admin → Appearance → Customizer
   - Check "Additional CSS" section
3. Clear WordPress object cache
4. Check browser console for CSS errors

## Build & Deployment

### Production Build

```bash
# From plugin directory
./build.sh

# Output: build/extrachill-docs.zip
```

### Deployment Process

1. Run build script to create ZIP
2. Download `build/extrachill-docs.zip`
3. Upload via WordPress plugin installer
4. Network activate (or activate on Blog ID 10)
5. Verify platforms seeded in admin

## Support & Contributing

### Getting Help

- Check [AGENTS.md](AGENTS.md) for technical details
- Review error logs in `/wp-content/debug.log`
- Inspect browser console for frontend errors

### Contributing

1. Follow WordPress coding standards
2. Test changes on local dev environment
3. Update AGENTS.md with technical changes
4. Create documentation for new features
5. Submit for code review

## Version History

See [docs/CHANGELOG.md](docs/CHANGELOG.md) for full version history.

## License

GPL v2 or later - Part of the Extra Chill Platform ecosystem.

---

**Plugin**: Extra Chill Docs
**Author**: Chris Huber
**Version**: 0.3.2
**WordPress**: 5.0+
**License**: GPL v2+
**Network**: Site-activated (Blog ID 10 only)
