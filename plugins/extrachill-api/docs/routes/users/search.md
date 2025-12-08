# User Search

Search for users by username, email, or display name with context-aware responses. Supports both public @mentions and admin-only full data searches.

## Endpoints

### Search Users

**Endpoint**: `GET /wp-json/extrachill/v1/users/search`

**Purpose**: Find users by search term for mentions, autocomplete, or admin relationship management.

**Permission**: 
- `mentions` context: Public access (no authentication required)
- `admin` context: Requires `manage_options` capability

**Parameters**:
- `term` (string, required) - Search query term (minimum 1 character for mentions, 2 for admin)
- `context` (string, optional) - Search context: `mentions` (default) or `admin`

**Request Examples**:

Mentions context (public @mention autocomplete):
```
GET /wp-json/extrachill/v1/users/search?term=chris&context=mentions
```

Admin context (user relationship management):
```
GET /wp-json/extrachill/v1/users/search?term=chris@example.com&context=admin
```

**Response - Mentions Context** (HTTP 200):
```json
[
  {
    "id": 1,
    "username": "chris",
    "slug": "chris"
  },
  {
    "id": 2,
    "username": "chrissy",
    "slug": "chrissy"
  }
]
```

**Response - Admin Context** (HTTP 200):
```json
[
  {
    "id": 1,
    "display_name": "Chris Huber",
    "username": "chris",
    "email": "chris@example.com",
    "avatar_url": "https://example.com/wp-content/uploads/avatar.jpg"
  },
  {
    "id": 5,
    "display_name": "Christina Brown",
    "username": "christine",
    "email": "christine@example.com",
    "avatar_url": "https://example.com/wp-content/uploads/avatar2.jpg"
  }
]
```

**Search Behavior**:

| Aspect | Mentions | Admin |
|--------|----------|-------|
| Min characters | 1 | 2 |
| Max results | 10 | 20 |
| Search columns | user_login, user_nicename | user_login, user_email, display_name |
| Response fields | id, username, slug | id, display_name, username, email, avatar_url |
| Sorting | By display_name ASC | By display_name ASC |

**Error Responses**:
- `400` - Missing search term or invalid request
- `403` - Admin context without proper permissions
- `500` - Search query failed

**Implementation Details**:
- Uses WordPress `WP_User_Query` for searching
- Wildcards applied: `*term*` for flexible matching
- Lightweight response for mentions (minimal data transfer)
- Full user data available for admin relationship management

**File**: `inc/routes/users/search.php`

---

## Usage Examples

### @Mention Autocomplete (Frontend)

```javascript
// Search for users to mention
fetch('/wp-json/extrachill/v1/users/search?term=' + searchTerm + '&context=mentions')
  .then(response => response.json())
  .then(users => {
    // users = [{id, username, slug}, ...]
    displayMentionSuggestions(users);
  });
```

### Admin User Relationship (Admin Panel)

```javascript
// Find users by email for role assignment
fetch('/wp-json/extrachill/v1/users/search?term=email&context=admin', {
  headers: {
    'X-WP-Nonce': wpApiSettings.nonce
  }
})
.then(response => response.json())
.then(users => {
  // users = [{id, display_name, username, email, avatar_url}, ...]
  populateUserSelector(users);
});
```

### PHP Example

```php
$response = wp_remote_get(
  rest_url( 'extrachill/v1/users/search' ),
  [
    'body' => [
      'term' => 'john',
      'context' => 'mentions'
    ]
  ]
);

$users = json_decode( wp_remote_retrieve_body( $response ), true );
```

---

## Usage Notes

**Mentions Context**:
- Designed for @mention autocomplete in community posts
- Public access - no authentication needed
- Returns minimal data to reduce payload size
- Useful for username-based lookups

**Admin Context**:
- Designed for user relationship management
- Admin-only access for security
- Returns full user data including email and avatar
- Useful for finding users by email or display name

**Performance**:
- Results limited to 10 (mentions) or 20 (admin) to prevent large datasets
- Pagination not supported - use more specific search terms for narrower results

**Related Endpoints**:
- [User Profile](users.md) - Get detailed user information
- [User Artists](artists.md) - Manage artist relationships for a user
