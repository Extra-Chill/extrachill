# ExtraChill Events

WordPress plugin providing seamless integration between ExtraChill themes and popular event plugins. Replaces previous dm-events integration with a flexible architecture supporting multiple event management plugins within the ExtraChill ecosystem.

## Plugin Information

- **Name**: ExtraChill Events
- **Version**: 1.0.0
- **Text Domain**: `extrachill-events`
- **Author**: Chris Huber
- **Author URI**: https://chubes.net
- **License**: GPL v2 or later
- **Requires at least**: 5.0
- **Tested up to**: 6.4
- **Network**: false

## Architecture

### Plugin Loading Pattern
- **Hybrid Loading**: Composer autoloader exists but plugin uses manual `require_once` for actual plugin classes
- **Class-Based Structure**: Object-oriented plugin architecture with singleton pattern
- **Composer Autoloader**: Only for development dependencies (PHPUnit, PHPCS when configured)
- **Manual Includes**: All integration classes loaded via direct `require_once` statements

### Core Classes
- **ExtraChillEvents**: Main plugin class (singleton), handles initialization and integration management (`extrachill-events.php`)
- **Future Integration Classes**: Architecture ready for Tribe Events, Event Calendar, and other popular event plugins

**Note**: This plugin replaces the previous dm-events integration that was removed from the theme.

## Key Features

### Event Plugin Integration
**Currently Supported**: Architecture implemented for major event plugins
- **Badge Styling Integration**: Maps event plugin taxonomy badges to ExtraChill's badge class structure
- **Festival-Specific Colors**: Enables custom festival colors (Bonnaroo, Coachella) from theme's badge-colors.css
- **Location Styling**: Converts venue taxonomies to location styling for regional color coding
- **Backward Compatibility**: Preserves original plugin classes while adding theme enhancements
- **Flexible Architecture**: Supports multiple event management plugins with unified styling

### Taxonomy Mapping System
**Badge Class Structure**:
- **festival** → `festival-badge festival-{slug}` (e.g., `festival-bonnaroo`)
- **venue/location** → `location-badge location-{slug}` (e.g., `location-charleston`)
- **other taxonomies** → Uses plugin's default styling (no mapping)

**Integration Hooks** (planned for specific event plugins):
- Event-specific badge class enhancement filters
- Wrapper class enhancement filters
- Breadcrumb system override filters
- Extensible hook system for future plugin integrations

### Breadcrumb Integration
- **Theme Override**: Replaces event plugin breadcrumbs with ExtraChill's breadcrumb system when available
- **Fallback Support**: Uses plugin's default breadcrumbs if theme function unavailable
- **Consistent Navigation**: Ensures unified breadcrumb experience across all event pages
- **Plugin Agnostic**: Works with multiple event management plugins

## Technical Implementation

### Plugin Architecture
- **Singleton Pattern**: Single instance management via `ExtraChillEvents::get_instance()`
- **Integration Detection**: Automatic detection of active event plugins via class existence checks
- **Modular Loading**: Dynamic integration loading based on available plugins
- **Future-Proof**: Extensible architecture for additional event plugin integrations

### WordPress Integration
- **Hook System**: Proper WordPress plugin initialization with activation/deactivation hooks
- **Text Domain Loading**: Translation support via `load_plugin_textdomain()`
- **Rewrite Rules**: Flush rewrite rules on activation/deactivation
- **Dependency Management**: Composer autoloader with fallback manual includes

### CSS Integration Strategy
**Badge Enhancement Process**:
1. Plugin renders badge with default classes
2. Integration filters add ExtraChill-compatible classes
3. Theme's badge-colors.css automatically applies custom styling
4. Maintains dual compatibility (plugin + theme styles)

**CSS Class Hierarchy**:
```css
.taxonomy-badges .taxonomy-badge.festival-badge.festival-bonnaroo {
    /* Custom Bonnaroo festival colors from theme */
}
.taxonomy-badges .taxonomy-badge.location-badge.location-charleston {
    /* Custom Charleston location colors from theme */
}
```

## Development Standards

### Code Organization
- **PSR-4 Autoloading**: All classes follow PSR-4 standards with proper namespacing
- **Single Responsibility**: Each integration class handles one specific plugin
- **WordPress Standards**: Full compliance with WordPress plugin development guidelines
- **Security Implementation**: Proper escaping, nonce verification, and input sanitization

### Build System
- **Standardized Build Process**: Uses `build.sh` script for production ZIP creation
- **Version Extraction**: Automatically reads version from plugin header
- **File Exclusion**: `.buildignore` patterns exclude development files
- **Composer Integration**: Production builds use `composer install --no-dev`

## Dependencies

### PHP Requirements
- **PHP**: 7.4+
- **WordPress**: 5.0+
- **Composer**: For dependency management and autoloading

### Development Dependencies
- **PHP CodeSniffer**: WordPress coding standards compliance
- **PHPUnit**: Unit testing framework
- **WPCS**: WordPress Coding Standards ruleset

### Plugin Dependencies
- **Optional Integration**: Major event plugins (auto-detected if active)
  - Tribe Events (planned)
  - Event Calendar (planned)
  - Events Manager (planned)
- **Theme Compatibility**: Works with any ExtraChill theme containing badge-colors.css
- **Extensible Architecture**: Ready for additional event plugin integrations

## Common Development Commands

### Building and Testing
```bash
# Install dependencies
composer install

# Create production build
./build.sh

# Run PHP linting
composer run lint:php

# Fix PHP coding standards
composer run lint:fix

# Run tests
composer run test
```

### Build Output
- **Production Package**: `dist/extrachill-events-{version}.zip`
- **File Exclusions**: Development files, vendor/, .git/, build tools
- **Structure Validation**: Ensures plugin integrity before packaging

## Integration Guidelines

### Adding New Event Plugin Support
1. **Create Integration Class**: New class in `includes/` following standardized integration pattern
2. **Detection Logic**: Add class existence check in `ExtraChillEvents::init_integrations()`
3. **Hook Integration**: Implement appropriate filter/action hooks for the target plugin
4. **Badge Mapping**: Map plugin's taxonomy structure to ExtraChill's badge classes
5. **Testing**: Verify integration with both plugins active and inactive
6. **Documentation**: Update plugin compatibility documentation

### Theme Integration Requirements
- **Badge Colors CSS**: Theme must include badge-colors.css with festival/location styling
- **Breadcrumb Function**: Optional `display_breadcrumbs()` function for breadcrumb override
- **CSS Compatibility**: Ensure `.taxonomy-badges` and `.taxonomy-badge` base classes exist

## Current Limitations

### Supported Plugins
- **Planning Stage**: Architecture implemented for major event management plugins
- **Target Plugins**: Tribe Events, Event Calendar, Events Manager, Modern Events Calendar
- **Replacement Function**: Replaces previous dm-events integration from theme

### Integration Scope
- **Visual Integration Focus**: Primarily handles badge styling and breadcrumb consistency
- **Theme Dependent**: Requires ExtraChill themes with appropriate CSS structure
- **Flexible Mapping**: Extensible taxonomy mapping system for various event plugins
- **Non-Intrusive**: Enhances plugins without modifying core functionality

## User Info

- Name: Chris Huber
- Dev website: https://chubes.net
- GitHub: https://github.com/chubes4
- Founder & Editor: https://extrachill.com
- Creator: https://saraichinwag.com