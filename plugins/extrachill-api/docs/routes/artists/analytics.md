# Artist Analytics Endpoint

## Route
`GET /wp-json/extrachill/v1/artists/{id}/analytics`

## Purpose
Retrieve link page performance analytics for an artist, including total views, clicks, and click data for top links. This endpoint provides artist-centric analytics replacing the legacy `/analytics/link-page` endpoint.

## Permission
- **GET**: Readable only by artist owner or admin using `ec_can_manage_artist()` permission check

## GET Request
Artist ID is extracted from the URL path. Optional date range parameter controls the analytics window.

### Query Parameters
| Parameter | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `date_range` | integer | No | 30 | Number of days of analytics to retrieve |

### GET Response
```json
{
  "artist_id": 123,
  "date_range": 30,
  "total_views": 1250,
  "total_clicks": 342,
  "top_links": [
    {
      "url": "https://open.spotify.com/artist/abc123",
      "clicks": 156
    },
    {
      "url": "https://instagram.com/artist",
      "clicks": 98
    },
    {
      "url": "https://youtube.com/@artist",
      "clicks": 67
    }
  ]
}
```

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `missing_permission` | 403 | Current user cannot manage this artist |
| `invalid_artist_id` | 404 | Artist post not found |
| `no_link_page` | 404 | Artist has not created a link page |
| `database_error` | 500 | Failed to retrieve analytics data |

## Implementation Notes
- Analytics data is retrieved from the platform's analytics tracking system
- The endpoint uses `extrachill_get_link_page_analytics` filter hook for data retrieval, allowing plugins to provide custom analytics providers
- The `date_range` parameter is flexible and accepts any positive integer for days
- Click data is ordered by highest click count first (descending)
- The `top_links` array includes both section links and social links
- Views represent page views of the link page itself
- Clicks represent clicks on individual links from the link page

## Related Endpoints
- `GET /artists/{id}` - Get core artist data
- `GET/PUT /artists/{id}/links` - Manage link page presentation
- `POST /analytics/click` - Track clicks including link page links (called by frontend)
- `POST /analytics/view` - Track link page views (called by frontend)

## Usage Examples

### Get Analytics for Last 30 Days
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/artists/123/analytics"
```

### Get Analytics for Last 90 Days
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/artists/123/analytics?date_range=90"
```

### Get Analytics for Last 7 Days
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/artists/123/analytics?date_range=7"
```

## Data Flow
1. Frontend link page calls `POST /analytics/view` when page loads
2. Frontend calls `POST /analytics/click` with `click_type: 'link_page_link'` when visitor clicks a link
3. Artist dashboard calls this endpoint to display analytics
4. Data aggregation is handled by the analytics provider via the filter hook
