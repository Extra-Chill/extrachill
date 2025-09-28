# ExtraChill Events

WordPress plugin providing seamless integration between ExtraChill themes and popular event plugins. Enables unified styling and functionality for calendar and event management within the ExtraChill ecosystem.

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

### PSR-4 Implementation
- **Composer Autoloading**: Configured with PSR-4 namespace `ExtraChillEvents\` mapping to `includes/`
- **Class-Based Structure**: Object-oriented plugin architecture with singleton pattern
- **Namespace Organization**: All classes use proper PHP namespacing for clean code organization

### Core Classes
- **ExtraChillEvents**: Main plugin class (singleton), handles initialization and integration management (`extrachill-events.php`)
- **ExtraChillEvents\DmEventsIntegration**: DM Events plugin integration with badge styling and breadcrumb override (`includes/class-dm-events-integration.php`)

## Key Features

### Event Plugin Integration
**Currently Supported**: DM Events plugin
- **Badge Styling Integration**: Maps DM Events taxonomy badges to ExtraChill's badge class structure
- **Festival-Specific Colors**: Enables custom festival colors (Bonnaroo, Coachella) from theme's badge-colors.css
- **Location Styling**: Converts venue taxonomies to location styling for regional color coding
- **Backward Compatibility**: Preserves original plugin classes while adding theme enhancements

### Taxonomy Mapping System
**Badge Class Structure**:
- **festival** → `festival-badge festival-{slug}` (e.g., `festival-bonnaroo`)
- **venue/location** → `location-badge location-{slug}` (e.g., `location-charleston`)
- **other taxonomies** → Uses plugin's default styling (no mapping)

**Integration Hooks**:
- `dm_events_badge_classes` - Individual badge class enhancement
- `dm_events_badge_wrapper_classes` - Wrapper class enhancement
- `dm_events_breadcrumbs` - Breadcrumb system override

### Breadcrumb Integration
- **Theme Override**: Replaces DM Events breadcrumbs with ExtraChill's breadcrumb system when available
- **Fallback Support**: Uses plugin's default breadcrumbs if theme function unavailable
- **Consistent Navigation**: Ensures unified breadcrumb experience across all pages

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
- **Optional Integration**: DM Events plugin (auto-detected if active)
- **Theme Compatibility**: Works with any ExtraChill theme containing badge-colors.css
- **Future Integrations**: Architecture supports Tribe Events, Event Calendar, etc.

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
1. **Create Integration Class**: New class in `includes/` following `DmEventsIntegration` pattern
2. **Detection Logic**: Add class existence check in `ExtraChillEvents::init_integrations()`
3. **Hook Integration**: Implement appropriate filter/action hooks for the target plugin
4. **Badge Mapping**: Map plugin's taxonomy structure to ExtraChill's badge classes
5. **Testing**: Verify integration with both plugins active and inactive

### Theme Integration Requirements
- **Badge Colors CSS**: Theme must include badge-colors.css with festival/location styling
- **Breadcrumb Function**: Optional `display_breadcrumbs()` function for breadcrumb override
- **CSS Compatibility**: Ensure `.taxonomy-badges` and `.taxonomy-badge` base classes exist

## Current Limitations

### Supported Plugins
- **DM Events**: Full integration with badge styling and breadcrumb override
- **Future Plugins**: Architecture ready for Tribe Events, Event Calendar, etc.

### Integration Scope
- **Badge Styling Only**: Currently focuses on visual integration, not functionality modification
- **Theme Dependent**: Requires ExtraChill themes with appropriate CSS structure
- **Manual Mapping**: Taxonomy mappings require manual configuration per plugin

## User Info

- Name: Chris Huber
- Dev website: https://chubes.net
- GitHub: https://github.com/chubes4
- Founder & Editor: https://extrachill.com
- Creator: https://saraichinwag.com