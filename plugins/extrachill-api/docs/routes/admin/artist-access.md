# Artist Access Management API

Handles artist platform access approval and rejection requests.

## Endpoints

### List Pending Requests

**Endpoint**: `GET /wp-json/extrachill/v1/admin/artist-access`

**Purpose**: Retrieve all pending artist and professional access requests.

**Permission**: Requires `manage_options` capability (network administrators only)

**Response** (HTTP 200):
```json
{
  "requests": [
    {
      "user_id": 123,
      "user_login": "username",
      "user_email": "user@example.com",
      "type": "artist",
      "requested_at": 1700000000
    }
  ]
}
```

**File**: `inc/routes/admin/artist-access.php`

---

### GET /extrachill/v1/admin/artist-access/{user_id}/approve

One-click email approval. Redirects to admin tools page after processing.

**Authentication**: Admin must be logged in with `manage_options` capability + valid HMAC token.

**Parameters**:
| Name | Type | Location | Description |
|------|------|----------|-------------|
| user_id | integer | path | User ID to approve |
| type | string | query | `artist` or `professional` |
| token | string | query | HMAC-signed approval token |

**Response**: HTTP redirect to `admin/tools.php?page=extrachill-admin-tools#artist-access-requests&approved=1`

**Token Generation**: Tokens are generated using `extrachill_api_generate_artist_access_token()` when the request email is sent. Tokens are HMAC-signed with `wp_salt('auth')` and do not expire until the request is processed.

---

### POST /extrachill/v1/admin/artist-access/{user_id}/approve

Admin tools button approval. Returns JSON response.

**Authentication**: Admin with `manage_options` capability + WP REST nonce.

**Parameters**:
| Name | Type | Location | Description |
|------|------|----------|-------------|
| user_id | integer | path | User ID to approve |
| type | string | body | `artist` or `professional` |

**Response**:
```json
{
  "success": true,
  "message": "User approved successfully"
}
```

---

### POST /extrachill/v1/admin/artist-access/{user_id}/reject

Admin tools button rejection. Returns JSON response.

**Authentication**: Admin with `manage_options` capability + WP REST nonce.

**Parameters**:
| Name | Type | Location | Description |
|------|------|----------|-------------|
| user_id | integer | path | User ID to reject |

**Response**:
```json
{
  "success": true,
  "message": "Request rejected"
}
```

## Token Security

Email approval links use HMAC-signed tokens instead of WordPress nonces to avoid session-dependency issues in multisite environments.

**Token Structure**:
- Payload: `{user_id}|{access_type}|{timestamp}`
- Signature: `hash_hmac('sha256', payload, wp_salt('auth'))`
- Final token: `base64_encode(payload + '.' + signature)`

**Validation**:
1. Decode and split token into payload + signature
2. Recalculate expected signature from payload
3. Timing-safe comparison of signatures
4. Verify user_id from payload matches URL parameter
5. Verify user has pending `artist_access_request` meta

## Related Functions

- `extrachill_api_generate_artist_access_token()` - Generate HMAC token for email links
- `extrachill_api_validate_artist_access_token()` - Validate HMAC token
- `ec_send_artist_access_approval_email()` - Send approval notification to user (in extrachill-admin-tools)
