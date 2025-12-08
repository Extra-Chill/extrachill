# Ad-Free License Management

Manage ad-free purchase licenses for users. Grant or revoke ad-free status via REST API with admin-only access.

## Endpoints

### Grant Ad-Free License

**Endpoint**: `POST /wp-json/extrachill/v1/admin/ad-free-license/grant`

**Purpose**: Grant an ad-free license to a user by username or email address.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**:
- `user_identifier` (string, required) - Username or email address of the user to grant license to

**Request Example**:
```json
{
  "user_identifier": "artist_name"
}
```

**Response** (HTTP 200):
```json
{
  "message": "Ad-free license granted to artist_name",
  "user_id": 123,
  "username": "artist_name",
  "email": "artist@example.com"
}
```

**Error Responses**:
- `400` - Missing identifier or invalid request
- `404` - User not found
- `409` - User already has an ad-free license
- `403` - Permission denied (not a network administrator)

**Implementation Details**:
- Accepts either username or email as identifier
- Stores license data as `extrachill_ad_free_purchased` user meta
- License includes purchase timestamp and username reference

**File**: `inc/routes/admin/ad-free-license.php`

---

### Revoke Ad-Free License

**Endpoint**: `DELETE /wp-json/extrachill/v1/admin/ad-free-license/{user_id}`

**Purpose**: Revoke an ad-free license from a user.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**:
- `user_id` (integer, required) - The user ID to revoke license from

**Response** (HTTP 200):
```json
{
  "message": "Ad-free license revoked for artist_name",
  "user_id": 123,
  "username": "artist_name"
}
```

**Error Responses**:
- `400` - Missing user ID
- `404` - User not found or user doesn't have an ad-free license
- `403` - Permission denied (not a network administrator)

**Implementation Details**:
- Deletes the `extrachill_ad_free_purchased` user meta
- Returns 404 if user has no active license

**File**: `inc/routes/admin/ad-free-license.php`

---

## Usage Notes

**License Storage**:
- Licenses are stored as WordPress user meta under the key `extrachill_ad_free_purchased`
- Meta contains purchase timestamp and order reference

**Integration**:
- Used by platform to control ad-serving behavior across sites
- Consumed by plugins checking `get_user_meta( $user_id, 'extrachill_ad_free_purchased', true )`

**Related Endpoints**:
- [User Management](users.md) - Get user profile with license status
