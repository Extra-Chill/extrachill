# ExtraChill Analytics Plugin - Network-Activated Analytics Tracking

Network-activated plugin providing unified event tracking system for key business metrics across the Extra Chill Platform multisite network.

## Architecture

### Event Tracking System

The plugin provides a centralized event tracking system that captures user interactions and business metrics asynchronously using `navigator.sendBeacon()` where appropriate.

**Event Types Tracked**:
- Newsletter signups
- User registrations
- Page views and link clicks
- Custom business events

### Abilities API Integration

The plugin exposes analytics tracking capabilities via the WordPress Abilities API:

```php
wp_register_ability_category( 'extrachill-analytics', [
    'label' => 'Analytics',
    'description' => 'Analytics tracking and reporting capabilities',
] );

wp_register_ability( 'extrachill/track-analytics-event', [
    'name' => 'Track Analytics Event',
    'description' => 'Track custom analytics events',
    'parameters' => [
        'type' => 'object',
        'properties' => [
            'event_name' => ['type' => 'string'],
            'event_data' => ['type' => 'object'],
            'user_id' => ['type' => 'integer'],
        ],
    ],
    'callback' => 'extrachill_analytics_track_event',
] );
```

### REST API Integration

The plugin consumes network/extrachill-api endpoints for data persistence:

- `POST /wp-json/extrachill/v1/analytics/view-count` - Track content views
- `POST /wp-json/extrachill/v1/analytics/click` - Track link clicks
- `POST /wp-json/extrachill/v1/analytics/link-page` - Track link page analytics

### Admin Dashboard Display

Provides analytics data for admin dashboards across the network, displaying key metrics and trends.

## Integration Points

### Cross-Plugin Communication

The analytics plugin integrates with other network plugins through:

- **extrachill-users**: User registration tracking
- **extrachill-newsletter**: Newsletter signup tracking
- **extrachill-api**: Data persistence via REST endpoints
- **Theme**: Analytics display in admin areas

### Data Storage

Analytics data is stored in a custom database table:
- `{base_prefix}extrachill_analytics_events` - Network-wide event tracking data (shared across all sites)

### Performance Considerations

- Asynchronous event capture using `navigator.sendBeacon()`
- Batched event processing to minimize database load
- Conditional loading based on admin context
- Network-wide data aggregation for dashboard reporting

## Development Standards

Follows Extra Chill Platform architectural patterns:
- Direct `require_once` loading pattern
- WordPress coding standards compliance
- Security-first development practices
- Network-activated plugin structure

## File Structure

```
extrachill-analytics/
├── extrachill-analytics.php     # Main plugin file
├── inc/
│   ├── core/
│   │   ├── abilities.php         # Abilities API registration
│   │   ├── tracking.php          # Event tracking functions
│   │   └── admin-display.php     # Admin dashboard integration
│   └── api/
│       └── endpoints.php         # REST API integration
└── assets/
    └── js/
        └── tracking.js           # Frontend tracking script
```