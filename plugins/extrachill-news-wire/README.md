# ExtraChill News Wire

WordPress plugin providing the Extra Chill Festival Wire content model, presentation, and Data Machine integration policy.

## Development Status

- **Active Refinement**: Festival Wire is the proven first vertical for a broader reactive music-news system.
- **Testing Focus**: Work centers on source provenance, template composition, discovery, and multisite integration.

## Overview

ExtraChill News Wire provides festival news coverage for wire.extrachill.com. It owns the `festival_wire` content model and presentation while Data Machine owns generic fetch, AI, publishing, tracking, and job evidence.

## Features

### Core Functionality
- **Festival Wire Custom Post Type**: Dedicated content management for festival news and coverage
- **Native Pagination**: Standard WordPress pagination using the theme's `extrachill_pagination()` function
- **Shared Taxonomies**: Network-owned festival and location classification
- **Template System**: Complete template hierarchy for archive and single post display
- **Auditable Automation**: A defined boundary between authoritative source data and AI-generated editorial output

### Frontend Features
- **Archive Pages**: Dedicated festival wire archive with filtering and pagination
- **Single Post Display**: Specialized single post templates with enhanced metadata
- **Content Cards**: Modular content display components
- **Mobile Responsive**: Fully responsive design with optimized mobile experience

## Build + deployment

Build the production ZIP with `./build.sh` (symlinked to `/.github/build.sh`).

Deployments and remote operations run through **Homeboy** (`homeboy/` in this repo).

The build artifact is `build/extrachill-news-wire.zip`.
### Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- jQuery (included with WordPress)

## Usage

### Content Management
1. Navigate to **Festival Wire** in the WordPress admin menu
2. Create new Festival Wire posts using the standard WordPress editor
3. Assign festival and location terms using the taxonomy metaboxes
4. Set featured images and excerpts for optimal display

### Frontend Display
- **Archive**: Visit `/festival-wire/` for the complete Festival Wire archive
- **Single Posts**: Individual festival wire posts display with enhanced metadata
- **Homepage Integration**: Compatible with homepage ticker widgets (requires theme support)

## Template Hierarchy

The plugin provides complete template coverage:

```
templates/
├── archive-festival_wire.php    # Archive page template
├── single-festival_wire.php     # Single post template
└── content-card.php             # Content card component
```

Templates automatically override theme templates when present.

## File Structure

```
extrachill-news-wire/
├── assets/                      # CSS and JavaScript files
│   ├── festival-wire.css       # Plugin styles
│   └── festival-wire.js        # Filter and FAQ accordion functionality
├── includes/                    # Core functionality
│   ├── festival-wire-post-type.php    # Custom post type registration
│   ├── festival-wire-query-filters.php # Query modifications
│   └── core/
│       ├── breadcrumbs.php             # Breadcrumb integration
│       └── post-meta.php               # Post meta handling
├── templates/                   # Template files
│   ├── archive-festival_wire.php       # Archive template
│   ├── single-festival_wire.php        # Single post template
│   ├── home-wire.php                   # Wire hub homepage
│   └── content-card.php                # Content card component
├── build.sh                     # Production build script
└── extrachill-news-wire.php     # Main plugin file
```

## Configuration

### Taxonomies
The plugin attaches these network-owned taxonomies:
- **Festival**: Primary festival classification
- **Location**: Geographic classification

### Custom Fields
Festival Wire posts support all standard WordPress features:
- Title and content editor
- Featured images
- Excerpts
- Author assignment
- Custom fields
- Revisions

### Data Machine Provenance

See [docs/provenance.md](docs/provenance.md) for source attribution, execution tracking, AI authority, story identity, and revision rules.

### Classification Audit

News Wire validates AI-decided festival and location values before Data Machine resolves or creates terms. Audit existing classifications without writing by default:

```bash
wp extrachill-news-wire classifications audit
```

After reviewing the proposed detach and merge actions, pass `--apply` to perform them.

## Development

### Build Process
```bash
# Navigate to plugin directory and create production build
cd extrachill-plugins/extrachill-news-wire
./build.sh

# Output: Only /build/extrachill-news-wire.zip file
```

### WordPress Standards
- Follows WordPress coding standards
- Implements proper security practices (nonces, sanitization, escaping)
- Uses WordPress hooks and filters for extensibility
- Includes proper internationalization support

## WordPress Compatibility

- **WordPress**: 5.0+
- **PHP**: 7.4+
- **Tested up to**: WordPress 6.4
- **Stable tag**: 0.3.9

## Support

This plugin was developed for the ExtraChill music publication platform. For support:

- **Developer**: Chris Huber
- **Website**: [wire.extrachill.com](https://wire.extrachill.com)
- **Development**: Part of the Extra Chill Platform ecosystem

## License

This plugin is developed for the ExtraChill platform. Please see the repository for license details.

## Changelog

See [docs/CHANGELOG.md](docs/CHANGELOG.md) for full version history.
