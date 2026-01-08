# Click Analytics Endpoint

## Route
`POST /wp-json/extrachill/v1/analytics/click`

## Purpose
Unified click tracking endpoint that routes to appropriate storage based on click type. Consolidates share tracking and link page click tracking into a single endpoint with routing logic.

## Request Body

### Common Parameters
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `click_type` | string | Yes | Type of click: `share`, `link_page_link` |
| `source_url` | string | Yes | Page URL where the click occurred |
| `destination_url` | string | Conditional | URL being navigated to (required for `link_page_link`) |
| `element_text` | string | No | Text content of the clicked element |

### Share Click Parameters
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `share_destination` | string | Yes (when `click_type=share`) | Share platform: `facebook`, `twitter`, `reddit`, `bluesky`, `linkedin`, `email`, `copy_link`, `copy_markdown`, `native`, etc. |

### Link Page Click Parameters
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `link_page_id` | integer | Yes (when `click_type=link_page_link`) | WordPress post ID of the artist link page |

## Storage Routing

| Click Type | Storage | Via |
| --- | --- | --- |
| `share` | `{base_prefix}extrachill_analytics_events` table | `extrachill_track_analytics_event('share_click', ...)` |
| `link_page_link` | `{prefix}extrch_link_page_daily_link_clicks` table | `do_action('extrachill_link_click_recorded', ...)` |

## URL Normalization

All destination URLs are automatically normalized before storage:
- Strips `_gl` (Google Linker parameter)
- Strips `_ga` (Google Analytics client ID)
- Strips `_ga_*` parameters (Google Analytics measurement IDs)
- Preserves affiliate IDs and custom query parameters

## Response
```json
{ "recorded": true }
```

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `missing_share_destination` | 400 | `share_destination` required for share click type |
| `missing_link_page_id` | 400 | `link_page_id` required for link_page_link click type |
| `missing_destination_url` | 400 | `destination_url` required for link_page_link click type |
| `function_missing` | 500 | Analytics tracking function not available |
| `tracking_failed` | 500 | Failed to record event |

## Examples

### Track Share Click
```bash
curl -X POST "https://extrachill.com/wp-json/extrachill/v1/analytics/click" \
  -H "Content-Type: application/json" \
  -d '{
    "click_type": "share",
    "share_destination": "twitter",
    "source_url": "https://extrachill.com/2025/01/article/",
    "destination_url": "https://twitter.com/intent/tweet?url=..."
  }'
```

### Track Link Page Click
```bash
curl -X POST "https://artist.extrachill.com/wp-json/extrachill/v1/analytics/click" \
  -H "Content-Type: application/json" \
  -d '{
    "click_type": "link_page_link",
    "link_page_id": 123,
    "source_url": "https://extrachill.link/artist-name",
    "destination_url": "https://open.spotify.com/artist/abc123",
    "element_text": "Listen on Spotify"
  }'
```

## JavaScript Integration

### Share Tracking
```javascript
function trackShare(destination, shareUrl) {
    const endpoint = '/wp-json/extrachill/v1/analytics/click';
    const data = {
        click_type: 'share',
        share_destination: destination,
        source_url: window.location.href,
        destination_url: shareUrl || window.location.href
    };

    if (navigator.sendBeacon) {
        navigator.sendBeacon(endpoint, new Blob([JSON.stringify(data)], { type: 'application/json' }));
    } else {
        fetch(endpoint, {
            method: 'POST',
            body: JSON.stringify(data),
            headers: { 'Content-Type': 'application/json' },
            keepalive: true
        }).catch(() => {});
    }
}
```

### Link Page Click Tracking
```javascript
sendBeacon(clickRestUrl, {
    click_type: 'link_page_link',
    link_page_id: linkPageId,
    source_url: window.location.href,
    destination_url: linkElement.href,
    element_text: linkText
});
```

## Action Hooks

### For Link Page Clicks
```php
// Fires when a link click is recorded on an artist link page
do_action('extrachill_link_click_recorded', $link_page_id, $normalized_url, $element_text);
```

The artist platform plugin listens to this action and writes to the daily aggregation table for artist-facing analytics.

## Future Click Types

The endpoint is designed to support additional click types:
- `internal_link` - Clicks on internal links within post content
- `taxonomy_badge` - Clicks on category/tag badges
- `cta` - Clicks on call-to-action buttons
- `navigation` - Clicks on navigation elements

These route to the network events table (`{base_prefix}extrachill_analytics_events`) for admin-level analytics.

Note: `{prefix}` indicates the current site’s `$wpdb->prefix` (per-site table), while `{base_prefix}` indicates the network `$wpdb->base_prefix` (shared across all sites).
