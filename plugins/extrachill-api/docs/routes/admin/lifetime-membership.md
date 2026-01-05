# Lifetime Extra Chill Membership Management

Manage Lifetime Extra Chill Memberships for users. Grant or revoke membership status via REST API with admin-only access. Primary benefit of this membership is an ad-free experience.

## Endpoints

### List Lifetime Memberships

**Endpoint**: `GET /wp-json/extrachill/v1/admin/lifetime-membership`

**Purpose**: Retrieve a paginated list of users with Lifetime Extra Chill Memberships.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**:
- `search` (string, optional) - Search term for username, email, or display name
- `page` (integer, optional) - Page number (default: 1)

**Response** (HTTP 200):
```json
{
  "members": [
    {
      "ID": 123,
      "user_login": "username",
      "user_email": "user@example.com",
      "purchased": "2024-10-27 14:30:00",
      "order_id": 12345
    }
  ],
  "total": 15,
  "total_pages": 1
}
```

**File**: `inc/routes/admin/lifetime-membership.php`

---

### Grant Lifetime Membership

**Endpoint**: `POST /wp-json/extrachill/v1/admin/lifetime-membership/grant`

**Purpose**: Grant a Lifetime Extra Chill Membership to a user by username or email address.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**:
- `user_identifier` (string, required) - Username or email address of the user to grant membership to

**Request Example**:
```json
{
  "user_identifier": "artist_name"
}
```

**Response** (HTTP 200):
```json
{
  "message": "Lifetime Extra Chill Membership granted to artist_name",
  "user_id": 123,
  "username": "artist_name",
  "email": "artist@example.com"
}
```

**Error Responses**:
- `400` - Missing identifier or invalid request
- `404` - User not found
- `409` - User already has a Lifetime Extra Chill Membership
- `403` - Permission denied (not a network administrator)

**Implementation Details**:
- Accepts either username or email as identifier
- Stores membership data as `extrachill_lifetime_membership` user meta
- Membership includes purchase timestamp and username reference

**File**: `inc/routes/admin/lifetime-membership.php`

---

### Revoke Lifetime Membership

**Endpoint**: `DELETE /wp-json/extrachill/v1/admin/lifetime-membership/{user_id}`

**Purpose**: Revoke a Lifetime Extra Chill Membership from a user.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**:
- `user_id` (integer, required) - The user ID to revoke membership from

**Response** (HTTP 200):
```json
{
  "message": "Lifetime Extra Chill Membership revoked for artist_name",
  "user_id": 123,
  "username": "artist_name"
}
```

**Error Responses**:
- `400` - Missing user ID
- `404` - User not found or user doesn't have a Lifetime Extra Chill Membership
- `403` - Permission denied (not a network administrator)

**Implementation Details**:
- Deletes the `extrachill_lifetime_membership` user meta
- Returns 404 if user has no active membership

**File**: `inc/routes/admin/lifetime-membership.php`

---

## Usage Notes

**Membership Storage**:
- Memberships are stored as WordPress user meta under the key `extrachill_lifetime_membership`
- Meta contains purchase timestamp and order reference

**Integration**:
- Used by platform to control ad-serving behavior across sites (membership provides ad-free experience)
- Consumed by plugins checking `get_user_meta( $user_id, 'extrachill_lifetime_membership', true )`

**Related Endpoints**:
- [User Profile](users.md) - Get user profile with membership status
