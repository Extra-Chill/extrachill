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

```javascript
const AnalyticsTracker = {
    init: function() {
        this.trackPageView();
        this.bindLinkClicks();
    },
    
    trackPageView: function() {
        if (!linkPageId) return;
        
        $.ajax({
            url: extrch_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'extrch_record_link_event',
                event_type: 'page_view',
                link_page_id: linkPageId,
                nonce: extrch_ajax.nonce
            }
        });
    },
    
    bindLinkClicks: function() {
        $(document).on('click', 'a.link-button', function(e) {
            const linkUrl = this.href;
            const linkText = $(this).text();
            
            // Track click
            $.ajax({
                url: extrch_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'link_page_click_tracking',
                    link_url: linkUrl,
                    link_text: linkText,
                    link_page_id: linkPageId,
                    nonce: extrch_ajax.nonce
                }
            });
            
            // Allow natural navigation
            return true;
        });
    }
};

document.addEventListener('DOMContentLoaded', AnalyticsTracker.init.bind(AnalyticsTracker));
```

### Server-Side Tracking

Location: `inc/link-pages/live/ajax/analytics.php`

```php
/**
 * Record page view event
 */
function extrch_record_link_event() {
    if (!wp_verify_nonce($_POST['nonce'], 'extrch_public_ajax_nonce')) {
        wp_die('Security check failed');
    }
    
    $link_page_id = (int) $_POST['link_page_id'];
    $event_type = sanitize_text_field($_POST['event_type']);
    
    if ($event_type === 'page_view') {
        record_page_view($link_page_id);
    }
    
    wp_send_json_success();
}
add_action('wp_ajax_extrch_record_link_event', 'extrch_record_link_event');
add_action('wp_ajax_nopriv_extrch_record_link_event', 'extrch_record_link_event');

/**
 * Record link click event
 */
function link_page_click_tracking() {
    if (!wp_verify_nonce($_POST['nonce'], 'extrch_public_ajax_nonce')) {
        wp_die('Security check failed');
    }
    
    $link_page_id = (int) $_POST['link_page_id'];
    $link_url = esc_url_raw($_POST['link_url']);
    
    record_link_click($link_page_id, $link_url);
    
    wp_send_json_success();
}
add_action('wp_ajax_link_page_click_tracking', 'link_page_click_tracking');
add_action('wp_ajax_nopriv_link_page_click_tracking', 'link_page_click_tracking');
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

### Management Interface

Legacy manage-link-page analytics templates and AJAX have been removed. Analytics is handled via the block and REST endpoints.

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

## Chart.js Integration

### Dashboard JavaScript

Location: `inc/link-pages/management/assets/js/analytics.js`

```javascript
const AnalyticsDashboard = {
    charts: {},
    
    init: function() {
        this.bindEvents();
        this.loadAnalyticsData();
    },
    
    bindEvents: function() {
        $('#analytics-date-range').on('change', this.onDateRangeChange.bind(this));
        $('#export-analytics').on('click', this.exportAnalytics.bind(this));
    },
    
    loadAnalyticsData: function() {
        const linkPageId = $('#link-page-id').val();
        const dateRange = $('#analytics-date-range').val();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'extrch_fetch_link_page_analytics',
                link_page_id: linkPageId,
                date_range: dateRange,
                nonce: analytics_nonce
            },
            success: this.renderCharts.bind(this)
        });
    },
    
    renderCharts: function(response) {
        if (!response.success) return;
        
        const data = response.data;
        
        // Page views chart
        this.renderPageViewsChart(data.page_views);
        
        // Link clicks chart
        this.renderLinkClicksChart(data.link_clicks);
        
        // Update summary
        this.updateSummary(data.summary);
    },
    
    renderPageViewsChart: function(pageViewData) {
        const ctx = document.getElementById('page-views-chart');
        
        // Destroy existing chart
        if (this.charts.pageViews) {
            this.charts.pageViews.destroy();
        }
        
        this.charts.pageViews = new Chart(ctx, {
            type: 'line',
            data: {
                labels: pageViewData.map(d => d.date),
                datasets: [{
                    label: 'Page Views',
                    data: pageViewData.map(d => d.views),
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
};

// Initialize when analytics tab is shown
document.addEventListener('tabShown', function(e) {
    if (e.detail.tab === 'analytics') {
        AnalyticsDashboard.init();
    }
});
```

## Data Pruning

### Automatic Cleanup

Analytics system includes automatic data pruning to prevent excessive database growth:

```php
/**
 * Prune old analytics data
 */
function prune_analytics_data() {
    global $wpdb;
    
    // Keep data for 2 years (configurable)
    $cutoff_date = date('Y-m-d', strtotime('-2 years'));
    
    // Prune page views
    $views_table = $wpdb->prefix . 'extrch_link_page_daily_views';
    $wpdb->query($wpdb->prepare("
        DELETE FROM {$views_table} WHERE stat_date < %s
    ", $cutoff_date));
    
    // Prune link clicks
    $clicks_table = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';
    $wpdb->query($wpdb->prepare("
        DELETE FROM {$clicks_table} WHERE stat_date < %s
    ", $cutoff_date));
}

// Schedule monthly pruning
function schedule_analytics_pruning() {
    if (!wp_next_scheduled('prune_analytics_data')) {
        wp_schedule_event(time(), 'monthly', 'prune_analytics_data');
    }
}
add_action('wp', 'schedule_analytics_pruning');
add_action('prune_analytics_data', 'prune_analytics_data');
```

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

### CSV Export

```php
function export_analytics_csv($link_page_id, $date_range) {
    $page_views = get_page_view_data($link_page_id, $date_range);
    $link_clicks = get_link_click_data($link_page_id, $date_range);
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="analytics-' . $link_page_id . '-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write page views data
    fputcsv($output, ['Date', 'Page Views']);
    foreach ($page_views as $row) {
        fputcsv($output, [$row['date'], $row['views']]);
    }
    
    fclose($output);
    exit;
}
```

## Integration Points

### Third-Party Analytics

Analytics system integrates with external services:

```javascript
// Send data to Google Analytics
function sendToGA(eventData) {
    if (typeof gtag !== 'undefined') {
        gtag('event', 'page_view', {
            'custom_parameter': eventData.link_page_id
        });
    }
}

// Send data to Meta Pixel
function sendToMetaPixel(eventData) {
    if (typeof fbq !== 'undefined') {
        fbq('track', 'ViewContent', {
            'content_ids': [eventData.link_page_id]
        });
    }
}
```