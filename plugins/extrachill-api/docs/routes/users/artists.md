# User Artist Relationships

Manage the relationship between users and artist profiles they manage. Control which artists are associated with each user account.

## Endpoints

### List User Artists

**Endpoint**: `GET /wp-json/extrachill/v1/users/{id}/artists`

**Purpose**: Retrieve the list of artist profiles managed by a specific user.

**Permission**: User must be viewing their own profile or be a network administrator

**Parameters**:
- `id` (integer, required) - The user ID whose artists to retrieve

**Response** (HTTP 200):
```json
[
  {
    "id": 456,
    "name": "The Cool Band",
    "slug": "cool-band",
    "profile_image_url": "https://example.com/wp-content/uploads/artist-thumb.jpg"
  },
  {
    "id": 789,
    "name": "Solo Artist",
    "slug": "solo-artist",
    "profile_image_url": "https://example.com/wp-content/uploads/solo-thumb.jpg"
  }
]
```

**Response Fields**:
- `id` - Artist profile post ID
- `name` - Artist display name (post title)
- `slug` - Artist URL slug (post name)
- `profile_image_url` - Thumbnail URL for artist profile image

**Error Responses**:
- `401` - User not logged in
- `403` - Cannot view other users' artists (non-admin)
- `404` - User not found
- `500` - Users plugin not active or required functions unavailable

**Implementation Details**:
- Uses `ec_get_artists_for_user()` to retrieve artist relationships
- Loads artist data from artist blog site
- Returns empty array if user has no managed artists

**File**: `inc/routes/users/artists.php`

---

### Add Artist Relationship

**Endpoint**: `POST /wp-json/extrachill/v1/users/{id}/artists`

**Purpose**: Add a new artist to a user's managed artists list.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**:
- `id` (integer, required) - The user ID to add artist to
- `artist_id` (integer, required) - The artist profile post ID to add

**Request Example**:
```json
{
  "artist_id": 456
}
```

**Response** (HTTP 200):
```json
{
  "success": true,
  "message": "Artist relationship added.",
  "user_id": 123,
  "artist_id": 456
}
```

**Error Responses**:
- `400` - Missing artist_id or relationship already exists
- `404` - User or artist not found
- `403` - Permission denied (not a network administrator)
- `500` - Artist platform not active or required functions unavailable

**Implementation Details**:
- Uses `bp_add_artist_membership()` to create relationship
- Verifies artist exists before adding relationship
- Returns error if relationship already exists
- Requires extrachill-artist-platform plugin to be active

**File**: `inc/routes/users/artists.php`

---

### Remove Artist Relationship

**Endpoint**: `DELETE /wp-json/extrachill/v1/users/{id}/artists/{artist_id}`

**Purpose**: Remove an artist from a user's managed artists list.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**:
- `id` (integer, required) - The user ID to remove artist from
- `artist_id` (integer, required) - The artist profile post ID to remove

**Response** (HTTP 200):
```json
{
  "success": true,
  "message": "Artist relationship removed.",
  "user_id": 123,
  "artist_id": 456
}
```

**Error Responses**:
- `400` - Failed to remove relationship
- `404` - User not found
- `403` - Permission denied (not a network administrator)
- `500` - Artist platform not active or required functions unavailable

**Implementation Details**:
- Uses `bp_remove_artist_membership()` to delete relationship
- Returns error if relationship cannot be removed
- Requires extrachill-artist-platform plugin to be active

**File**: `inc/routes/users/artists.php`

---

## Usage Notes

**Artist Relationships**:
- Users can manage multiple artist profiles simultaneously
- A single artist can be managed by multiple users
- Relationships stored via `bp_add_artist_membership()` function

**Admin vs User Access**:
- Users can only view/manage their own artists
- Network admins can view and manage any user's artists
- Returns 403 if non-admin attempts to access another user's artists

**Artist Blog Location**:
- Artist profiles are stored on a dedicated artist blog site
- Uses `ec_get_blog_id( 'artist' )` to locate artist blog

**Related Endpoints**:
- [Artist Core Data](../artist/artist.md) - Get artist profile details
- [User Profile](users.md) - Get user information including artist count
