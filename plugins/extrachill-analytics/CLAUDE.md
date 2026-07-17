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

### Browser and REST Integration

- Signed first-party browser pageviews submit to the Analytics tracking Ability.
- Extra Chill API owns thin public click and impression adapters; Analytics validates canonical events and persists them.
- The retired post-only pageview adapter is not a supported integration path.

### Admin Dashboard Display

Provides analytics data for admin dashboards across the network, displaying key metrics and trends.

## Integration Points

### Cross-Plugin Communication

The analytics plugin integrates with other network plugins through:

- **extrachill-users**: User registration tracking
- **extrachill-newsletter**: Newsletter signup tracking
- **extrachill-api**: Thin public click and impression request adapters
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
