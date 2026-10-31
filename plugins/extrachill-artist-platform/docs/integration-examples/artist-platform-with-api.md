# Artist Platform REST API Integration

The artist platform exposes comprehensive REST API endpoints through the extrachill-api plugin, enabling mobile apps, third-party integrations, and external tools to access artist data.

## REST API Base

All artist platform endpoints are available under:

```
/wp-json/extrachill/v1/artist/
```

## Available Endpoints

### Artist Profiles

#### Get Single Artist

```
GET /wp-json/extrachill/v1/artist/{artist_id}
```

Returns complete artist profile data:

```json
{
  "id": 123,
  "name": "Extra Chill",
  "slug": "extra-chill",
  "description": "Independent music journalism",
  "image": "https://artist.extrachill.com/wp-content/uploads/artist-image.jpg",
  "genres": ["indie", "hip-hop"],
  "social_links": {
    "spotify": "https://spotify.com/artist/...",
    "twitter": "@extrachill",
    "instagram": "@extrachill"
  },
  "subscriber_count": 1250,
  "link_page_url": "https://extrachill.link/extra-chill"
}
```

#### List Artists

```
GET /wp-json/extrachill/v1/artist?page=1&per_page=20&search=extra
```

Parameters:
- `page` (int) - Page number (default: 1)
- `per_page` (int) - Items per page (default: 20, max: 100)
- `search` (string) - Search artist name/description
- `genre` (string) - Filter by genre slug
- `orderby` (string) - `name`, `created`, `subscribers` (default: `name`)
- `order` (string) - `asc` or `desc`

### Link Pages

#### Get Link Page Data

```
GET /wp-json/extrachill/v1/artist/{artist_id}/link-page
```

Returns link page configuration and current data:

```json
{
  "id": 456,
  "artist_id": 123,
  "title": "Extra Chill Links",
  "url": "https://extrachill.link/extra-chill",
  "links": [
    {
      "id": "spotify",
      "title": "Spotify",
      "url": "https://spotify.com/artist/...",
      "clicks": 342
    }
  ],
  "appearance": {
    "background_color": "#000000",
    "text_color": "#ffffff",
    "button_color": "#53940b"
  },
  "stats": {
    "total_views": 12450,
    "total_clicks": 3280,
    "subscribers": 145
  }
}
```

### Subscribers

#### List Artist Subscribers

```
GET /wp-json/extrachill/v1/artist/{artist_id}/subscribers?page=1&per_page=50
```

Requires authentication (artist owner only)

Returns:

```json
{
  "total": 145,
  "page": 1,
  "per_page": 50,
  "subscribers": [
    {
      "id": 789,
      "email": "fan@example.com",
      "subscribed_date": "2024-01-15T10:30:00Z",
      "status": "active"
    }
  ]
}
```

#### Export Subscribers

```
POST /wp-json/extrachill/v1/artist/{artist_id}/subscribers/export
```

Triggers export of all subscribers to CSV, returns download link:

```json
{
  "success": true,
  "download_url": "https://artist.extrachill.com/wp-content/uploads/exports/subscribers-123.csv",
  "expires_in_hours": 24
}
```

### Analytics

#### Get Link Page Analytics

```
GET /wp-json/extrachill/v1/artist/{artist_id}/analytics?start_date=2024-01-01&end_date=2024-01-31
```

Returns analytics data for a date range:

```json
{
  "artist_id": 123,
  "start_date": "2024-01-01",
  "end_date": "2024-01-31",
  "summary": {
    "total_views": 5420,
    "total_clicks": 1280,
    "unique_visitors": 3100,
    "new_subscribers": 45
  },
  "daily_breakdown": [
    {
      "date": "2024-01-01",
      "views": 175,
      "clicks": 42,
      "clicks_by_link": {
        "spotify": 28,
        "instagram": 14
      }
    }
  ]
}
```

## Authentication

### Required Authentication

Most endpoints require authentication as the artist owner:

```javascript
// Include WordPress authentication cookie
fetch('/wp-json/extrachill/v1/artist/123', {
  credentials: 'include', // Include WordPress session cookie
  headers: {
    'Content-Type': 'application/json'
  }
});
```

### Bearer Access Token (Optional)

For external applications, use a Bearer access token:

```javascript
const token = '...';

fetch('/wp-json/extrachill/v1/artist/123', {
  headers: {
    'Authorization': 'Bearer ' + token,
    'Content-Type': 'application/json'
  }
});
```

## Request/Response Examples

### Example 1: Get Artist Profile via JavaScript

```javascript
// Get artist by ID
async function getArtist(artistId) {
    const response = await fetch(`/wp-json/extrachill/v1/artist/${artistId}`);
    
    if (!response.ok) {
        throw new Error('Failed to fetch artist: ' + response.status);
    }
    
    return response.json();
}

// Usage
getArtist(123)
    .then(artist => {
        console.log('Artist:', artist.name);
        console.log('Genre:', artist.genres);
        console.log('Subscribers:', artist.subscriber_count);
    })
    .catch(error => console.error('Error:', error));
```

### Example 2: Search Artists

```javascript
// Search artists with filters
async function searchArtists(query, genre = null) {
    const params = new URLSearchParams({
        search: query,
        per_page: 20
    });
    
    if (genre) {
        params.append('genre', genre);
    }
    
    const response = await fetch(`/wp-json/extrachill/v1/artist?${params}`);
    return response.json();
}

// Usage
searchArtists('indie', 'indie-rock')
    .then(results => {
        results.forEach(artist => {
            console.log(`${artist.name} - ${artist.genres.join(', ')}`);
        });
    });
```

### Example 3: Get Link Page Analytics

```javascript
// Fetch analytics with date range
async function getAnalytics(artistId, startDate, endDate) {
    const params = new URLSearchParams({
        start_date: startDate,
        end_date: endDate
    });
    
    const response = await fetch(
        `/wp-json/extrachill/v1/artist/${artistId}/analytics?${params}`,
        {
            credentials: 'include' // Include auth cookie
        }
    );
    
    if (!response.ok) {
        throw new Error('Unauthorized');
    }
    
    return response.json();
}

// Usage
const start = '2024-01-01';
const end = '2024-01-31';
getAnalytics(123, start, end)
    .then(data => {
        console.log('Views:', data.summary.total_views);
        console.log('Clicks:', data.summary.total_clicks);
        console.log('Daily:', data.daily_breakdown);
    });
```

## Error Handling

### Common Error Codes

**404 Not Found**
```json
{
  "code": "rest_post_invalid_id",
  "message": "Invalid post id.",
  "data": {
    "status": 404
  }
}
```

**401 Unauthorized**
```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to do this.",
  "data": {
    "status": 401
  }
}
```

**400 Bad Request**
```json
{
  "code": "rest_invalid_param",
  "message": "Invalid parameter(s): start_date",
  "data": {
    "status": 400,
    "params": ["start_date"]
  }
}
```

### Error Handling Pattern

```javascript
async function apiCall(endpoint) {
    try {
        const response = await fetch(endpoint, {
            credentials: 'include'
        });
        
        if (!response.ok) {
            const error = await response.json();
            
            if (response.status === 401) {
                console.error('Please log in');
            } else if (response.status === 404) {
                console.error('Resource not found');
            } else {
                console.error('Error:', error.message);
            }
            
            return null;
        }
        
        return response.json();
    } catch (error) {
        console.error('Network error:', error);
        return null;
    }
}
```

## Rate Limiting

API endpoints are rate-limited to prevent abuse:

- **Authenticated requests**: 60 requests per minute
- **Unauthenticated requests**: 20 requests per minute
- **Headers returned**:
  - `X-RateLimit-Limit`: Maximum requests allowed
  - `X-RateLimit-Remaining`: Requests remaining in current window
  - `X-RateLimit-Reset`: Unix timestamp when limit resets

```javascript
// Check rate limit headers
fetch('/wp-json/extrachill/v1/artist')
    .then(response => {
        const limit = response.headers.get('X-RateLimit-Limit');
        const remaining = response.headers.get('X-RateLimit-Remaining');
        console.log(`Requests remaining: ${remaining}/${limit}`);
        return response.json();
    });
```

## Use Cases

### Mobile App Integration

```javascript
// Fetch artist profile data for mobile app
const artistId = 123;
const artist = await fetch(`/wp-json/extrachill/v1/artist/${artistId}`)
    .then(r => r.json());

// Display artist info
console.log(`${artist.name} - ${artist.genres.join(', ')}`);

// Get social links
artist.social_links.forEach(link => {
    console.log(`${link.type}: ${link.url}`);
});

// Link to link page
window.location = artist.link_page_url;
```

### Third-Party Dashboard Integration

```javascript
// Create dashboard widget showing artist stats
async function updateArtistWidget(artistId) {
    const data = await fetch(
        `/wp-json/extrachill/v1/artist/${artistId}/analytics?days=30`,
        { credentials: 'include' }
    ).then(r => r.json());
    
    document.querySelector('.widget-views').textContent = data.summary.total_views;
    document.querySelector('.widget-clicks').textContent = data.summary.total_clicks;
    document.querySelector('.widget-subscribers').textContent = data.summary.new_subscribers;
}
```

### Export Workflow

```javascript
// Trigger subscriber export
async function exportSubscribers(artistId) {
    const response = await fetch(
        `/wp-json/extrachill/v1/artist/${artistId}/subscribers/export`,
        {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' }
        }
    );
    
    const data = await response.json();
    
    if (data.success) {
        // Trigger download
        const link = document.createElement('a');
        link.href = data.download_url;
        link.download = 'subscribers.csv';
        link.click();
    }
}
```

## Related Endpoints

See [extrachill-api plugin documentation](../../extrachill-api/CLAUDE.md) for:
- All available REST endpoints
- Authentication methods
- Request/response formats
- Error handling patterns

## Cross-Reference

- [Artist Platform CLAUDE.md](../CLAUDE.md) - Full plugin architecture
- [Link Page System](../CLAUDE.md#link-page-system) - Link page implementation
- [Analytics System](../CLAUDE.md#analytics-system) - Analytics tracking
