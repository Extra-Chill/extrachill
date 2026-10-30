# User Profile

Retrieve detailed user profile information including account status, permissions, and artist management capabilities.

## Endpoints

### Get User Profile

**Endpoint**: `GET /wp-json/extrachill/v1/users/{id}`

**Purpose**: Retrieve comprehensive user profile data with permission-based field visibility.

**Permission**: User must be logged in. Can view own profile or another user's limited public profile (if admin, get full data).

**Parameters**:
- `id` (integer, required) - The user ID to retrieve

**Response - Own Profile or Admin View** (HTTP 200):
```json
{
  "id": 123,
  "display_name": "Chris Huber",
  "username": "chris",
  "slug": "chris",
  "avatar_url": "https://example.com/wp-content/uploads/avatar.jpg",
  "profile_url": "https://profiles.example.com/members/chris/",
  "is_team_member": true,
  "last_active": 1704067200,
  "email": "chris@example.com",
  "is_lifetime_member": true,
  "is_artist": true,
  "is_professional": false,
  "can_create_artists": true,
  "artist_count": 5,
  "registered": "2024-01-01T12:00:00+00:00"
}
```

**Response - Other User Profile** (HTTP 200):
```json
{
  "id": 456,
  "display_name": "Jane Artist",
  "username": "jane",
  "slug": "jane",
  "avatar_url": "https://example.com/wp-content/uploads/avatar2.jpg",
  "profile_url": "https://profiles.example.com/members/jane/",
  "is_team_member": false,
  "last_active": 1704000000
}
```

**Response Fields**:

| Field | Visibility | Description |
|-------|------------|-------------|
| `id` | Public | User ID |
| `display_name` | Public | User's display name |
| `username` | Public | User login (username) |
| `slug` | Public | User URL slug (nicename) |
| `avatar_url` | Public | Avatar image URL (96px) |
| `profile_url` | Public | User profile page URL |
| `is_team_member` | Public | Whether user is a team member |
| `last_active` | Public | Last activity timestamp |
| `email` | Private | User email address |
| `is_lifetime_member` | Private | Lifetime membership status (ad-free) |
| `is_artist` | Private | Artist status flag |
| `is_professional` | Private | Professional status flag |
| `can_create_artists` | Private | Permission to create artist profiles |
| `artist_count` | Private | Number of managed artist profiles |
| `registered` | Private | User registration date (ISO 8601) |

**Error Responses**:
- `401` - User not logged in
- `404` - User not found
- `500` - Dependencies unavailable

**Implementation Details**:
- Public fields always returned to logged-in users
- Extended fields only returned for own profile or network administrators
- Team member status determined by `ec_is_team_member()` if available
- Artist count retrieved via `ec_get_artists_for_user()`
- Avatar uses default Gravatar if no custom avatar set
- Last active timestamp from user meta `last_active`

**File**: `inc/routes/users/users.php`

---

## Usage Examples

### Get Own Profile (JavaScript)

```javascript
// Fetch current user's full profile
const userId = wpApiSettings.userId; // Get from wp_localize_script

fetch(`/wp-json/extrachill/v1/users/${userId}`, {
  headers: {
    'X-WP-Nonce': wpApiSettings.nonce
  }
})
.then(response => response.json())
.then(user => {
  console.log(`Email: ${user.email}`);
  console.log(`Lifetime Member: ${user.is_lifetime_member}`);
  console.log(`Manages ${user.artist_count} artists`);
});
```

### Get Another User's Public Profile

```javascript
// Fetch public data about another user
fetch('/wp-json/extrachill/v1/users/456')
  .then(response => response.json())
  .then(user => {
    // Only public fields available
    console.log(user.display_name);
    console.log(user.profile_url);
  });
```

### Admin View of User (PHP)

```php
$response = wp_remote_get(
  rest_url( 'extrachill/v1/users/123' ),
  [
    'headers' => [
      'X-WP-Nonce' => wp_create_nonce( 'wp_rest' )
    ]
  ]
);

$user = json_decode( wp_remote_retrieve_body( $response ), true );

// Full data available to admins
if ( $user['is_lifetime_member'] ) {
  // User has premium status (ad-free)
}
```

---

## Usage Notes

**Permission Model**:
- Own profile: All fields visible
- Other user's profile: Only public fields visible
- Network admin: All fields visible for any user
- Non-logged-in: 401 error

**User Metadata**:
- `user_is_artist` - Flag indicating artist status
- `user_is_professional` - Flag indicating professional status
- `extrachill_lifetime_membership` - Lifetime membership data (ad-free)
- `extrachill_team` - Team member status (0 or 1)
- `last_active` - Last activity timestamp

**Integration Pattern**:
```php
// Build full user profile response
$user = get_userdata( $user_id );
$response = extrachill_api_build_user_response( $user, $is_full_data );
```

**Related Endpoints**:
- [User Search](search.md) - Find users by username or email
- [User Artists](artists.md) - Manage artist profiles for a user
- [Lifetime Membership](../admin/lifetime-membership.md) - Grant/revoke membership status
- [Team Members](../admin/team-members.md) - Manage team member status
