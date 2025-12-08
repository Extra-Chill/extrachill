# Analytics System

Comprehensive analytics tracking for link pages with daily aggregation, click tracking, and dashboard reporting.

## Database Architecture

### Analytics Tables

Two primary tables handle analytics data:

```sql
-- Daily page view aggregation
CREATE TABLE wp_extrch_link_page_daily_views (
    view_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    link_page_id bigint(20) unsigned NOT NULL,
    stat_date date NOT NULL,
    view_count bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (view_id),
    UNIQUE KEY unique_daily_view (link_page_id, stat_date)
);

-- Daily link click aggregation  
CREATE TABLE wp_extrch_link_page_daily_link_clicks (
    click_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    link_page_id bigint(20) unsigned NOT NULL,
    stat_date date NOT NULL,
    link_url varchar(2083) NOT NULL,
    click_count bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (click_id),
    UNIQUE KEY unique_daily_link_click (link_page_id, stat_date, link_url(191)),
    KEY link_page_date (link_page_id, stat_date)
);
```

### Database Management

Location: `inc/database/link-page-analytics-db.php`

```php
/**
 * Create or update analytics tables using dbDelta
 */
function extrch_create_or_update_analytics_table() {
    $current_db_version = get_option('extrch_analytics_db_version');
    
    if ($current_db_version === EXTRCH_ANALYTICS_DB_VERSION) {
        return; // Database is up to date
    }
    
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    // Create both tables
    dbDelta($sql_views);
    dbDelta($sql_clicks);
    
    // Update version
    update_option('extrch_analytics_db_version', EXTRCH_ANALYTICS_DB_VERSION);
}

// Hook to admin_init for automatic updates
add_action('admin_init', 'extrch_create_or_update_analytics_table');
```

## Public Tracking

### Client-Side Tracking

Location: `inc/link-pages/live/assets/js/link-page-public-tracking.js`

Tracks page views and link clicks using sendBeacon API for reliable delivery with Fetch API fallback

### Server-Side Tracking

Location: `inc/link-pages/live/ajax/analytics.php` - REST API endpoint enqueuer

The analytics system receives tracking data via REST API requests:

```php
/**
 * Record page view event via REST API
 */
function extrch_record_link_event() {
    // REST API endpoint handler receives data
    $link_page_id = (int) $request->get_param('link_page_id');
    $event_type = sanitize_text_field($request->get_param('event_type'));
    
    if ($event_type === 'page_view') {
        record_page_view($link_page_id);
    }
    
    return rest_ensure_response(['success' => true]);
}

/**
 * Record link click event via REST API
 */
function link_page_click_tracking($request) {
    // REST API endpoint handler receives data
    $link_page_id = (int) $request->get_param('link_page_id');
    $link_url = esc_url_raw($request->get_param('link_url'));
    
    record_link_click($link_page_id, $link_url);
    
    return rest_ensure_response(['success' => true]);
}
```

### Client-Side Fetch Integration

Location: `inc/link-pages/live/assets/js/link-page-public-tracking.js`

Public tracking uses Fetch API with sendBeacon fallback:

```javascript
// Track page view via Fetch API with sendBeacon fallback
function trackPageView(linkPageId) {
    const data = new FormData();
    data.append('link_page_id', linkPageId);
    data.append('event_type', 'page_view');
    
    // Use sendBeacon for reliability
    navigator.sendBeacon(
        `/wp-json/extrachill/v1/analytics/track`,
        data
    );
}

// Track link click via Fetch API
function trackLinkClick(linkPageId, linkUrl) {
    fetch('/wp-json/extrachill/v1/analytics/track', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            link_page_id: linkPageId,
            event_type: 'link_click',
            link_url: linkUrl
        })
    });
}
```

## URL Normalization

Link click URLs are automatically normalized before storage to prevent analytics clutter from auto-generated tracking parameters. This keeps the dashboard readable while preserving intentional query strings like affiliate IDs.

### Stripped Parameters

The following Google Analytics cross-domain linking parameters are removed:
- `_gl` - Google Linker parameter
- `_ga` - Google Analytics client ID
- `_ga_*` - Google Analytics measurement ID parameters (e.g., `_ga_L362LLL9KM`)

### Implementation

**Client-side** (`inc/link-pages/live/assets/js/link-page-public-tracking.js`):
- `normalizeTrackedUrl()` strips parameters before sending the beacon request

**Server-side** (`inc/link-pages/live/ajax/analytics.php`):
- `extrch_normalize_tracked_url()` provides redundant sanitization before database insert

Both implementations preserve all other query parameters (affiliate IDs, custom campaign params, etc.).

## Data Aggregation

### Page View Recording

```php
function record_page_view($link_page_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'extrch_link_page_daily_views';
    $today = current_time('Y-m-d');
    
    // Use INSERT ... ON DUPLICATE KEY UPDATE for atomic operation
    $sql = $wpdb->prepare("
        INSERT INTO {$table_name} (link_page_id, stat_date, view_count) 
        VALUES (%d, %s, 1)
        ON DUPLICATE KEY UPDATE view_count = view_count + 1
    ", $link_page_id, $today);
    
    $wpdb->query($sql);
}
```

### Link Click Recording

```php
function record_link_click($link_page_id, $link_url) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';
    $today = current_time('Y-m-d');
    
    $sql = $wpdb->prepare("
        INSERT INTO {$table_name} (link_page_id, stat_date, link_url, click_count) 
        VALUES (%d, %s, %s, 1)
        ON DUPLICATE KEY UPDATE click_count = click_count + 1
    ", $link_page_id, $today, $link_url);
    
    $wpdb->query($sql);
}
```

## Analytics Dashboard

### Link Page Analytics Block

**Location**: `src/blocks/link-page-analytics/`

Dedicated Gutenberg block providing comprehensive analytics interface for link page performance tracking and analysis.

**Block Features**:
- Chart.js-powered analytics dashboard
- Daily page view aggregation with visual charts
- Link click tracking and breakdown by URL
- Date range filtering for custom time periods
- Visual performance metrics and trends
- Artist context switching for multi-artist users
- Responsive design for desktop and mobile viewing

**Component Architecture**:
- **Analytics.js**: Main analytics dashboard component with chart rendering
- **ArtistSwitcher.js**: Artist context switching interface
- **AnalyticsContext.js**: Context for analytics data management
- **useAnalytics.js**: Custom hook for analytics data and queries
- **API Client**: REST API integration for analytics data endpoints

**Block Registration**:
```php
// Registered separately from link-page-editor block
register_block_type( __DIR__ . '/build/blocks/link-page-analytics' );
```

**Version History**:
- **v1.1.11+**: Analytics moved to separate dedicated block for better organization and performance
- **v1.2.0+**: Enhanced block with artist switching and improved data visualization

### Management Interface

Analytics displayed via REST API in dedicated Gutenberg analytics block with `Analytics` component:

Location: `src/blocks/link-page-analytics/components/Analytics.js`

### Data Queries

```php
function get_page_view_data($link_page_id, $date_range) {
    global $wpdb;
    
    list($start_date, $end_date) = parse_date_range($date_range);
    
    $table_name = $wpdb->prefix . 'extrch_link_page_daily_views';
    
    $results = $wpdb->get_results($wpdb->prepare("
        SELECT stat_date, view_count 
        FROM {$table_name} 
        WHERE link_page_id = %d 
        AND stat_date BETWEEN %s AND %s 
        ORDER BY stat_date ASC
    ", $link_page_id, $start_date, $end_date));
    
    return array_map(function($row) {
        return [
            'date' => $row->stat_date,
            'views' => (int) $row->view_count
        ];
    }, $results);
}

function get_link_click_data($link_page_id, $date_range) {
    global $wpdb;
    
    list($start_date, $end_date) = parse_date_range($date_range);
    
    $table_name = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';
    
    $results = $wpdb->get_results($wpdb->prepare("
        SELECT link_url, SUM(click_count) as total_clicks
        FROM {$table_name} 
        WHERE link_page_id = %d 
        AND stat_date BETWEEN %s AND %s 
        GROUP BY link_url 
        ORDER BY total_clicks DESC
    ", $link_page_id, $start_date, $end_date));
    
    return $results;
}
```

### Chart.js Integration

Charts are rendered directly in the Gutenberg block editor via React components

## Data Pruning

### Automatic Cleanup

Analytics system includes automatic data pruning via scheduled cron job:

Location: `inc/database/link-page-analytics-db.php`

The pruning cron runs monthly to maintain database performance by removing data older than the configured retention period (default: 90 days)

## Performance Optimization

### Database Indexes

Tables include optimized indexes for common queries:

```sql
-- Page views table indexes
UNIQUE KEY unique_daily_view (link_page_id, stat_date)

-- Link clicks table indexes  
UNIQUE KEY unique_daily_link_click (link_page_id, stat_date, link_url(191))
KEY link_page_date (link_page_id, stat_date)
```

### Query Optimization

Analytics queries use prepared statements and appropriate LIMIT clauses:

```php
// Efficient top links query
function get_top_performing_links($link_page_id, $date_range, $limit = 10) {
    global $wpdb;
    
    list($start_date, $end_date) = parse_date_range($date_range);
    
    $table_name = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';
    
    return $wpdb->get_results($wpdb->prepare("
        SELECT link_url, SUM(click_count) as total_clicks
        FROM {$table_name} 
        WHERE link_page_id = %d 
        AND stat_date BETWEEN %s AND %s 
        GROUP BY link_url 
        ORDER BY total_clicks DESC 
        LIMIT %d
    ", $link_page_id, $start_date, $end_date, $limit));
}
```

## Export Functionality

Analytics data can be exported via REST API endpoints for integration with external reporting tools

## Integration Points

### Third-Party Analytics

The plugin integrates with external analytics services via:
- Google Tag Manager (GTM) tracking codes configured in TabAdvanced
- Meta Pixel (Facebook) integration for conversion tracking
- Custom event tracking via REST API endpoints