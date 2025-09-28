# ExtraChill News Wire

WordPress plugin providing festival and music news wire functionality with custom post types, AJAX features, and migration tools.

## Overview

ExtraChill News Wire is a standalone WordPress plugin extracted from the ExtraChill theme. It provides comprehensive festival news coverage functionality including custom post types, real-time content loading, content tip submission forms, and administrative migration tools.

## Features

### Core Functionality
- **Festival Wire Custom Post Type**: Dedicated content management for festival news and coverage
- **Real-time Content Loading**: AJAX-powered infinite scroll and dynamic content updates
- **Tip Submission System**: Community-driven content submission with Turnstile verification
- **Custom Taxonomies**: Festival, category, and data source taxonomy support
- **Template System**: Complete template hierarchy for archive and single post display

### Administrative Tools
- **Tag Migration**: One-time migration tool to convert post tags to festival taxonomy
- **Author Migration**: Bulk author reassignment for all Festival Wire posts
- **Content Management**: Full WordPress admin integration with custom post type support

### Frontend Features
- **Archive Pages**: Dedicated festival wire archive with filtering and pagination
- **Single Post Display**: Specialized single post templates with enhanced metadata
- **Content Cards**: Modular content display components
- **Mobile Responsive**: Fully responsive design with optimized mobile experience

## Installation

### Production Build Installation
1. Navigate to plugin directory and create production build:
   ```bash
   cd extrachill-plugins/extrachill-news-wire
   ./build.sh
   ```
2. Go to WordPress Admin > Plugins > Add New
3. Click "Upload Plugin" and select the ZIP file from `dist/` directory
4. Activate the plugin

### Manual Installation
1. Copy plugin files to WordPress plugins directory:
   ```bash
   cp -r extrachill-plugins/extrachill-news-wire /path/to/wp-content/plugins/
   ```
2. Activate the plugin through the WordPress admin

### Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- jQuery (included with WordPress)

## Usage

### Content Management
1. Navigate to **Festival Wire** in the WordPress admin menu
2. Create new Festival Wire posts using the standard WordPress editor
3. Assign festivals, categories, and data sources using the taxonomy metaboxes
4. Set featured images and excerpts for optimal display

### Migration Tools
Access migration tools via **Tools > Festival Wire Migration**:

#### Tag to Festival Migration
- Converts existing post tags on Festival Wire posts to the Festival taxonomy
- Removes migrated tags and deletes unused tags
- One-time operation with detailed reporting

#### Author Migration
- Bulk reassign all Festival Wire posts to a selected author
- Useful for content consolidation and author management
- Provides migration statistics and confirmation

### Frontend Display
- **Archive**: Visit `/festival_wire/` for the complete news wire archive
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
│   └── festival-wire.js        # AJAX functionality
├── includes/                    # Core functionality
│   ├── festival-wire-post-type.php    # Custom post type registration
│   ├── festival-wire-ajax.php         # AJAX handlers
│   └── festival-wire-query-filters.php # Query modifications
├── templates/                   # Template files
├── build.sh                     # Production build script
└── extrachill-news-wire.php     # Main plugin file
```

## Configuration

### Taxonomies
The plugin registers and utilizes these taxonomies:
- **Festival**: Primary festival classification
- **Category**: Standard WordPress categories
- **Data Source**: Content source tracking

### Custom Fields
Festival Wire posts support all standard WordPress features:
- Title and content editor
- Featured images
- Excerpts
- Author assignment
- Custom fields
- Revisions

## Development

### Build Process
```bash
# Navigate to plugin directory and create production build
cd extrachill-plugins/extrachill-news-wire
./build.sh

# Output: dist/extrachill-news-wire-{version}.zip
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
- **Stable tag**: 1.0

## Support

This plugin was developed for the ExtraChill music publication platform. For support:

- **Developer**: Chris Huber
- **Website**: [extrachill.com](https://extrachill.com)
- **Development**: Part of the Extra Chill Platform ecosystem

## License

This plugin is developed for the ExtraChill platform. Please see the repository for license details.

## Changelog

### 1.0
- Initial release
- Extracted from ExtraChill theme
- Complete Festival Wire functionality
- Migration tools for tag and author management
- Full template system implementation
- AJAX-powered content loading
- Community tip submission system