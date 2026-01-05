# Extra Chill Analytics - Development Documentation

## Architectural Overview

The ExtraChill Analytics plugin provides network-wide analytics tracking and reporting using a unified event tracking system. It tracks key business metrics (newsletter signups, user registrations, page views) via action hook listeners and stores events in a centralized database table for querying and dashboard display.

## Core Patterns

### 1. Asynchronous View Tracking

**Pattern**: Non-blocking view counting using modern browser APIs to prevent page load performance impact.

**Implementation**:
- `navigator.sendBeacon()` preferred (modern browsers, automatic cleanup)
- Fallback to `fetch()` with `keepalive` option
- Excludes preview mode via `is_preview()` check
- Only loads on singular pages (`is_singular()`)

**File**: `inc/core/assets.php` → `extrachill_analytics_enqueue_view_tracking()`

**Data Flow**:
```
User visits post
↓
wp_enqueue_scripts hook fires
↓
view-tracking.js enqueued with localized data (postId, endpoint)
↓
IIFE executes on page load
↓
POST request to /extrachill/v1/analytics/view
↓
extrachill-api plugin handles endpoint
↓
ec_track_post_views() increments post meta
```

### 2. REST API Endpoint Usage

**Pattern**: Plugin does not define its own REST endpoints; consumes network-activated endpoint from extrachill-api plugin.

**Endpoint**: `POST /extrachill/v1/analytics/view`

**Plugin Contract**:
- **Provider**: extrachill-api plugin defines and registers the endpoint
- **Consumer**: extrachill-analytics plugin calls the endpoint
- **Handler**: `ec_track_post_views()` in `inc/core/view-counts.php` processes the request

**Function Existence Check**:
```php
if (function_exists('ec_track_post_views')) {
    ec_track_post_views($post_id);
}
```

**Rationale**: Centralized API infrastructure ensures consistent endpoint patterns across all network plugins.

### 3. Post Meta Storage

**Pattern**: Uses WordPress post meta for view count storage, allowing easy retrieval via standard WP functions.

**Meta Key**: `ec_post_views`

**Functions**:
- `ec_track_post_views($post_id)` - Increment view count
- `ec_get_post_views($post_id)` - Get current count (defaults to current post)
- `ec_the_post_views($post_id, $echo)` - Display formatted count

**File**: `inc/core/view-counts.php`

**Usage Example**:
```php
// In template file
ec_the_post_views(); // Outputs: "1,234 views"

// Get raw count for API response
$views = ec_get_post_views($post_id);
```

### 4. Network Admin Integration

**Pattern**: WordPress multisite submenu page pattern with asset loading restrictions.

**Menu Structure**:
- Parent: `extrachill-multisite` (defined by extrachill-multisite plugin)
- Page: `extrachill-analytics`
- Capability: `manage_network_options`

**Hook Priority**: 20 (fires after core network menu items)

**Asset Loading**: Only loads on specific submenu page via hook parameter check:
```php
if ('extrachill-multisite_page_extrachill-analytics' !== $hook) {
    return;
}
```

**File**: `inc/admin/network-menu.php`

### 5. React Admin Dashboard

**Pattern**: WordPress Gutenberg-style React app using wp-element and wp-api-fetch.

**Dependencies**:
- `wp-element` - React-like DOM library (React 18)
- `wp-i18n` - Internationalization
- `wp-api-fetch` - REST API client

**Container**: `#extrachill-analytics-app` with loading state

**File**: `assets/js/admin-analytics.js`

**React 18 Initialization Pattern**:
- Wrap initialization in `DOMContentLoaded` event listener
- Use `wp.element.createRoot()` (React 18 API) not `wp.element.render()` (React 17)
- Add `window.wp` availability check before accessing dependencies
- Wrap in try/catch for error handling with console logging

**Why DOM Ready Check?** WordPress loads dependencies asynchronously; script may execute before `window.wp` is available.

**Why React 18 API?** Modern WordPress uses React 18; legacy `render()` deprecated and less performant.

**Note**: Currently v0.1.0 placeholder; dashboard displays welcome message pending data integration.

## Loading Patterns

### Frontend Asset Loading

**Hook**: `wp_enqueue_scripts`

**Condition**: `is_singular() && !is_preview()`

**Localization**:
```php
wp_localize_script('extrachill-view-tracking', 'ecViewTracking', [
    'postId' => get_the_ID(),
    'endpoint' => rest_url('extrachill/v1/analytics/view'),
]);
```

### Admin Asset Loading

**Hook**: `admin_enqueue_scripts`

**Condition**: Specific page hook string matching

**Versioning**: Uses `filemtime()` for automatic cache busting

## Architectural Decisions

### 1. Why REST API for View Tracking?
- **Performance**: Asynchronous, non-blocking request
- **Reliability**: `sendBeacon` ensures request completes even if user navigates away
- **Consistency**: Follows modern WordPress patterns
- **Debuggability**: REST API requests visible in browser dev tools

### 2. Why Post Meta Over Custom Table?
- **Simplicity**: Uses built-in WordPress storage mechanism
- **Portability**: No database schema migrations required
- **Performance**: WordPress caches meta queries
- **Integration**: Works with standard WP_Query and post list views

### 3. Why Consume extrachill-api Endpoint?
- **Single Source of Truth**: All API endpoints defined in one plugin
- **Version Control**: API namespace (`extrachill/v1`) managed centrally
- **Dependency Management**: Clear dependency declaration in plugin requirements
- **Consistency**: Same authentication and permission patterns across all endpoints

### 4. Why React for Admin Dashboard?
- **WordPress Native**: Uses same libraries as Gutenberg
- **Performance**: Client-side rendering reduces server load
- **Extensibility**: Easy to add charts, filters, and interactive components
- **Maintainability**: Modern JavaScript patterns familiar to WP developers

## Plugin Integration Points

### extrachill-api Integration

**Required Endpoints** (must be registered by extrachill-api):
- `POST /extrachill/v1/analytics/view` - View tracking

**Registration Pattern** (in extrachill-api):
```php
// inc/routes/analytics/view-count.php
register_rest_route('extrachill/v1', '/analytics/view', [
    'methods' => 'POST',
    'callback' => function($request) {
        $post_id = $request->get_param('post_id');
        if (function_exists('ec_track_post_views')) {
            ec_track_post_views($post_id);
        }
        return new WP_REST_Response(['success' => true]);
    },
    'permission_callback' => '__return_true', // Public endpoint
]);
```

### Theme Integration

**Display View Counts in Theme**:
```php
// In single.php or template parts
if (function_exists('ec_the_post_views')) {
    ec_the_post_views();
}
```

**Get View Count Programmatically**:
```php
$views = ec_get_post_views($post_id);
```

## Event Tracking System

### 6. Unified Event Storage

**Pattern**: Network-wide custom table for storing all analytics events with flexible JSON payload.

**Table**: `{base_prefix}_ec_events` (shared across multisite network)

**Schema**:
- `id` - Auto-increment primary key
- `event_type` - Event identifier (e.g., 'newsletter_signup', 'user_registration')
- `event_data` - JSON payload with event-specific data
- `source_url` - Page URL where event occurred
- `blog_id` - Multisite blog context
- `user_id` - User ID if logged in (nullable)
- `created_at` - Timestamp

**File**: `inc/database/events-db.php`

### 7. Event Tracking Functions

**File**: `inc/core/events.php`

**Core Functions**:
```php
// Track an event
ec_track_event( 'newsletter_signup', array(
    'context' => 'homepage',
    'list_id' => 'abc123',
), 'https://extrachill.com/festivals/' );

// Query events
$events = ec_get_events( array(
    'event_type' => 'newsletter_signup',
    'date_from'  => '2025-01-01',
    'limit'      => 50,
) );

// Get aggregated stats
$stats = ec_get_event_stats( 'newsletter_signup', 30 ); // Last 30 days
```

### 8. Event Listeners

**Pattern**: Loosely-coupled listeners that hook into events fired by other plugins.

**Newsletter Signups** (`inc/listeners/newsletter.php`):
- Listens to: `extrachill_newsletter_subscribed`
- Tracks: context, list_id, source_url

**User Registrations** (`inc/listeners/registration.php`):
- Listens to: `extrachill_new_user_registered`
- Tracks: user_id, registration_source, registration_method, registration_page

### 9. REST API Endpoints

**Provided by extrachill-api plugin**:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/analytics/events` | GET | Query events with filters |
| `/analytics/events/summary` | GET | Aggregated stats by event type |

**Query Parameters** (`/analytics/events`):
- `event_type` - Filter by event type
- `blog_id` - Filter by blog
- `date_from` / `date_to` - Date range
- `limit` / `offset` - Pagination

**Summary Parameters** (`/analytics/events/summary`):
- `event_type` (required) - Event type to aggregate
- `days` - Lookback period (default 30, 0 for all time)
- `blog_id` - Optional blog filter

## Event Data Flow

### Newsletter Signup Flow
```
User submits form on /festivals/bonnaroo-2025/
↓
newsletter.js POSTs to /extrachill/v1/newsletter/subscribe
with { email, context: 'content', source_url }
↓
REST endpoint calls extrachill_multisite_subscribe($email, $context, $source_url)
↓
Sendy API returns success
↓
do_action('extrachill_newsletter_subscribed', $context, $list_id, $source_url)
↓
Analytics listener calls ec_track_event('newsletter_signup', {...}, $source_url)
↓
Event stored in {base_prefix}_ec_events table
```

### User Registration Flow
```
User registers via form or Google OAuth
↓
extrachill-users creates user
↓
do_action('extrachill_new_user_registered', $user_id, $page, $source, $method)
↓
Analytics listener calls ec_track_event('user_registration', {...}, $page)
↓
Event stored in {base_prefix}_ec_events table
```

## Version History

### 0.2.0 (Event Tracking)
- Unified event tracking system with custom table
- Newsletter signup tracking with source URL
- User registration tracking
- REST API endpoints for querying events
- Event listeners for cross-plugin integration

### 0.1.0 (Initial)
- Basic view tracking via REST API
- Preview exclusion
- Network admin dashboard scaffold
- Asset versioning with filemtime()

## Future Enhancements (Planned)

- Dashboard analytics data integration
- Network-wide view count aggregation
- Popular posts reporting
- Date range filtering in dashboard
- Export functionality
- Additional event types (contact form, purchases, etc.)
