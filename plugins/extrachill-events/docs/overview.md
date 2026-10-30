# ExtraChill Events Overview

WordPress plugin providing seamless integration between ExtraChill themes and the data-machine-events plugin for events.extrachill.com (blog ID 7).

## What This Plugin Does

ExtraChill Events creates a dedicated event calendar site within the ExtraChill multisite network by:

- **Template Integration**: Renders homepage content via the theme action hook `extrachill_homepage_content` and overrides archive templates via the `extrachill_template_archive` filter.
- **Visual Integration**: Maps data-machine-events badges and buttons to ExtraChill theme styling
- **Breadcrumb System**: Provides custom navigation breadcrumbs with "Extra Chill → Events" hierarchy
- **Related Events**: Displays related events by venue and location taxonomies
- **SEO Optimization**: Redirects `/events/` post type archive to homepage for link equity consolidation

## events.extrachill.com Architecture

This plugin powers **events.extrachill.com** (site #7 in the multisite network) as a centralized event hub:

- **Data Machine Integration**: Handles event import and automation pipelines
- **data-machine-events Calendar**: Provides calendar block with filtering and pagination
- **Centralized Management**: Single source of truth for all network events
- **Clean Separation**: Main site focuses on content, events site handles calendar functionality

## Core Components

### Template System
- **Homepage Template**: Displays static page content with calendar block support
- **Archive Template**: Renders calendar block with context-aware taxonomy filtering
- **SEO Redirect**: 301 redirects `/events/` archive to homepage

### Integration System
- **Badge Styling**: Maps festival/location taxonomies to theme badge classes
- **Button Styling**: Applies theme button classes to modal and ticket buttons
- **Taxonomy Exclusion**: Hides venue and artist badges (displayed via metadata)
- **Share Button**: Renders share button alongside ticket button

### Breadcrumb System
- **Custom Root**: "Extra Chill → Events" breadcrumb structure
- **Context-Aware Trails**: Different breadcrumbs for homepage, archives, and single events
- **Navigation Labels**: "Back to Events" instead of "Back to Home"

### Styling System
- **Single Event CSS**: Card treatment for event info grid and action button flexbox
- **Calendar CSS**: Homepage calendar enhancements (minimal placeholder)
- **Theme Dependencies**: Loads theme's single-post.css and sidebar.css

## WordPress Requirements

- **WordPress**: 5.0+
- **PHP**: 7.4+
- **Required Plugins**: Data Machine, data-machine-events
- **Theme Requirement**: ExtraChill theme with breadcrumb functions and badge-colors.css

## Blog ID Targeting

All template overrides and integrations target **blog ID 7** (events.extrachill.com) specifically. The plugin does not affect other sites in the network.
