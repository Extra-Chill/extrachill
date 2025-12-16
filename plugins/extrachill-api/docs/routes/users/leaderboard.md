# User Leaderboard Endpoint

**Endpoint**: `GET /wp-json/extrachill/v1/users/leaderboard`

**Purpose**: Retrieve paginated leaderboard of top users ranked by total points with badges and rank information.

## Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number for pagination |
| `per_page` | int | 25 | Results per page (max: 100) |

## Response

**HTTP 200 Success**:

```json
{
  "items": [
    {
      "id": 123,
      "display_name": "Chris Huber",
      "username": "chris",
      "slug": "chris",
      "avatar_url": "https://example.com/wp-content/uploads/avatar.jpg",
      "profile_url": "https://community.extrachill.com/u/chris/",
      "registered": "2024-01-01T12:00:00+00:00",
      "points": 1250,
      "rank": "Gold Member",
      "badges": ["verified", "contributor"],
      "position": 1
    },
    {
      "id": 124,
      "display_name": "Jane Smith",
      "username": "janesmith",
      "slug": "janesmith",
      "avatar_url": "https://example.com/wp-content/uploads/avatar2.jpg",
      "profile_url": "https://community.extrachill.com/u/janesmith/",
      "registered": "2024-02-15T08:30:00+00:00",
      "points": 985,
      "rank": "Silver Member",
      "badges": ["active"],
      "position": 2
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 25,
    "total": 156,
    "total_pages": 7
  }
}
```

## Response Fields

### Items Array

Each leaderboard item contains:

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | User ID |
| `display_name` | string | User's display name |
| `username` | string | User login |
| `slug` | string | User nicename (URL-safe) |
| `avatar_url` | string | Custom or Gravatar avatar URL |
| `profile_url` | string | Community profile URL or author archive |
| `registered` | string | Registration date in ISO 8601 format |
| `points` | float | Total user points from `extrachill_total_points` meta |
| `rank` | string | User rank (if `ec_get_rank_for_points()` function available) |
| `badges` | array | User badges array (if `ec_get_user_badges()` function available) |
| `position` | int | 1-based leaderboard position |

### Pagination

| Field | Type | Description |
|-------|------|-------------|
| `page` | int | Current page number |
| `per_page` | int | Results per page |
| `total` | int | Total number of ranked users |
| `total_pages` | int | Total page count |

## Permission

**Public** - No authentication required

## Data Source

Users are ranked by the `extrachill_total_points` user meta field in descending order.

## Dependencies

The endpoint gracefully handles optional dependencies:

- **`ec_get_rank_for_points()`** - Determines user rank from points total (optional)
  - If unavailable, `rank` field returns empty string
  - Can be provided by any plugin implementing gamification

- **`ec_get_user_badges()`** - Retrieves array of user badges (optional)
  - If unavailable, `badges` field returns empty array
  - Can be provided by badge/achievement systems

- **`ec_get_user_profile_url()`** - Generates profile URLs (optional)
  - If unavailable, falls back to `get_author_posts_url()`
  - Enables community profile linking when available

## Examples

### Get Top 10 Users

```javascript
fetch('/wp-json/extrachill/v1/users/leaderboard?per_page=10')
  .then(response => response.json())
  .then(data => {
    data.items.forEach(user => {
      console.log(`${user.position}. ${user.display_name} - ${user.points} points`);
    });
  });
```

### Get Specific Page

```javascript
fetch('/wp-json/extrachill/v1/users/leaderboard?page=2&per_page=25')
  .then(response => response.json())
  .then(data => console.log(`Page ${data.pagination.page} of ${data.pagination.total_pages}`));
```

### PHP Example

```php
$response = wp_remote_get(
  rest_url('extrachill/v1/users/leaderboard'),
  array('body' => array('per_page' => 50))
);

if (is_wp_error($response)) {
  // Handle error
  return;
}

$data = json_decode(wp_remote_retrieve_body($response), true);

foreach ($data['items'] as $user) {
  echo $user['position'] . '. ' . $user['display_name'] . "\n";
}
```

## Use Cases

- **Leaderboard Display**: Show top community members on frontend
- **Gamification**: Display user rankings with points and badges
- **Community Engagement**: Showcase active members and contributors
- **Admin Tools**: Monitor user activity and point distribution
- **Mobile Apps**: Provide leaderboard data to mobile clients

## Notes

- Results are always sorted by points in descending order
- The `position` field reflects the actual leaderboard position (accounting for pagination)
- Per-page limit of 100 prevents excessively large responses
- Pagination is 1-based (first page is page 1)
- Rank and badge integrations are optional and gracefully degrade

## Technical Details

- **Query Method**: Uses `WP_User_Query` with `meta_key='extrachill_total_points'` and `orderby='meta_value_num'`
- **Performance**: Two queries executed (one for results, one for total count)
- **Network-Wide**: Queries all users across the multisite network
- **Caching**: No built-in caching; implement at application level if needed
