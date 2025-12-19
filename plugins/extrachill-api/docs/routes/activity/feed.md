# Activity Feed

Retrieve paginated activity feed with configurable filtering and visibility controls.

## Endpoint

### Get Activity Feed

**Endpoint**: `GET /wp-json/extrachill/v1/activity`

**Purpose**: Query activity events across the network with support for filtering by blog, actor, type, and visibility settings.

**Permission**: Requires logged-in user. Private visibility requires `manage_options` capability.

**Parameters**:
- `cursor` (integer, optional) - Pagination cursor for keyset pagination
- `limit` (integer, optional) - Number of items to return
- `blog_id` (integer, optional) - Filter by specific blog
- `actor_id` (integer, optional) - Filter by activity actor (user ID)
- `visibility` (string, optional) - Filter by visibility: `public` (default) or `private` (requires admin)
- `types` (array, optional) - Array of activity type strings to include
- `taxonomies` (object, optional) - Filter by taxonomy terms using AND logic. Keys are taxonomy slugs, values are term slugs.
  - Allowed: `category`, `post_tag`, `festival`, `location`, `venue`, `artist`, `promoter`
  - Example: `?taxonomies[venue]=the-fillmore&taxonomies[location]=charleston`

**Response** (HTTP 200):
```json
{
  "items": [
    {
      "id": 123,
      "created_at": "2025-01-15T10:30:00Z",
      "type": "post_published",
      "blog_id": 1,
      "actor_id": 456,
      "summary": "...",
      "visibility": "public",
      "primary_object": {
        "object_type": "post",
        "blog_id": 1,
        "id": "789"
      },
      "secondary_object": null,
      "data": {
        "post_type": "datamachine_events",
        "card": {
          "title": "Event Title",
          "excerpt": "...",
          "permalink": "https://..."
        },
        "taxonomies": {
          "venue": [{"id": 123, "slug": "the-fillmore", "name": "The Fillmore"}],
          "location": [{"id": 42, "slug": "charleston", "name": "Charleston"}]
        }
      }
    }
  ],
  "next_cursor": 123
}
```

**File**: `inc/routes/activity/feed.php`

## Permission Model

| Scenario | Permission | Result |
|----------|-----------|--------|
| Logged-in user, public visibility | - | Allowed |
| Logged-in user, private visibility | `manage_options` | Allowed if admin, error if not |
| Anonymous user | - | 401 Unauthorized |

## Implementation Details

- Uses `extrachill_api_activity_query()` to retrieve activity records
- Supports keyset pagination via `cursor` parameter for efficient large result sets
- Visibility filtering prevents unauthorized access to private activity
- Type filtering allows clients to request specific activity categories
- Taxonomy filtering uses AND logic across taxonomies (must match all specified)

## Taxonomy Filtering

Filter activity by taxonomy terms. Multiple taxonomies use AND logic.

**Request:**
```
GET /activity?taxonomies[venue]=the-fillmore&taxonomies[location]=charleston
```

**Allowed Taxonomies:**

| Taxonomy | Description |
|----------|-------------|
| `category` | Post categories |
| `post_tag` | Post tags |
| `festival` | Festival taxonomy |
| `location` | Geographic location |
| `venue` | Event venues |
| `artist` | Artist taxonomy |
| `promoter` | Event promoters |

Terms are matched by slug. Only activity items with matching terms for ALL specified taxonomies are returned.

## Response Taxonomy Data

Post activity items include taxonomy terms in `data.taxonomies`. Each term includes `id`, `slug`, and `name` for client-side display. Taxonomies with no assigned terms are omitted from the response.

## Emitted Activity Types

Current activity emitters generate these `type` values:
- `post_published`
- `post_updated`
- `comment_created`

## Post Types Included

Post-related activity is emitted for any post type that transitions to `publish` (except `attachment`). The post type is available at `item.data.post_type`.

Post types registered in this repository that may appear:
- Core: `post`, `page`
- CPTs: `artist_profile`, `artist_link_page`, `newsletter`, `ec_doc`, `festival_wire`, `wook_horoscope`, `ec_chat`

## Error Responses

**Not Logged In** (HTTP 401):
```json
{
  "code": "rest_forbidden",
  "message": "Must be logged in.",
  "data": { "status": 401 }
}
```

**Insufficient Permissions** (HTTP 403):
```json
{
  "code": "rest_forbidden",
  "message": "Admin access required.",
  "data": { "status": 403 }
}
```

**Activity System Unavailable** (HTTP 500):
```json
{
  "code": "missing_activity",
  "message": "Activity system not loaded.",
  "data": { "status": 500 }
}
```

## Dependencies

- **extrachill-api**: Activity system module (`inc/activity/` directory)
- **extrachill-multisite**: Recommended for cross-site context

## Integration

Used by platform features requiring activity tracking and display:
- Community activity streams
- User activity dashboards
- Real-time notifications
- Analytics and reporting
