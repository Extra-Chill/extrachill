# Google OAuth Authentication

Authenticate users with Google OAuth using ID tokens and return access + refresh tokens for API access.

## Endpoints

### Google OAuth Login

**Endpoint**: `POST /wp-json/extrachill/v1/auth/google`

**Purpose**: Authenticate user via Google ID token and generate access + refresh tokens for API access.

**Permission**: Public (no authentication required)

**Parameters**:
- `id_token` (string, required) - Google ID token from Google Sign-In
- `device_id` (string, required) - UUID v4 device identifier for session tracking
- `device_name` (string, optional) - Human-readable device name (e.g., "Chrome on MacBook")
- `from_join` (boolean, optional) - Whether registration flow initiated from join (default: false)
- `remember` (boolean, optional) - Whether to extend token expiry (default: true)
- `set_cookie` (boolean, optional) - Whether to set WordPress authentication cookie (default: false)
- `success_redirect_url` (string, optional) - URL to redirect to after successful authentication
- `registration_page` (string, optional) - URL where authentication occurred
- `registration_source` (string, optional) - Source label (e.g. `web`, `extrachill-app`)
- `registration_method` (string, optional) - Method label (e.g. `google`)

**Request Example**:
```json
{
  "id_token": "eyJhbGciOiJSUzI1NiIsImtpZCI6IjExMjM0NTY3ODkwIn0...",
  "device_id": "550e8400-e29b-41d4-a716-446655440000",
  "device_name": "Chrome on MacBook Pro",
  "remember": true,
  "success_redirect_url": "https://extrachill.com/dashboard"
}
```

**Response** (HTTP 200):
```json
{
  "access_token": "...",
  "access_expires_at": "2025-01-15T10:30:00Z",
  "refresh_token": "...",
  "refresh_expires_at": "2025-02-15T10:30:00Z",
  "user": {
    "id": 123,
    "username": "user",
    "display_name": "User Name",
    "avatar_url": "https://...",
    "profile_url": "https://community.extrachill.com/forums/users/user/"
  }
}
```

**Error Responses**:
- `400` - Missing/invalid ID token, invalid device_id format, or validation errors
- `500` - Google OAuth service unavailable or dependency missing

**Implementation Details**:
- Validates device_id as UUID v4 format
- Calls `ec_google_login_with_tokens()` from extrachill-users plugin
- Handles user creation via Google if account doesn't exist
- Supports device tracking for session management
- Optionally sets WordPress authentication cookie

**File**: `inc/routes/auth/google.php`

---

## Usage Examples

### Web Application Google Sign-In

```javascript
// After Google Sign-In button click
async function loginWithGoogle(response) {
  const deviceId = generateUuidV4();
  
  const authResponse = await fetch('/wp-json/extrachill/v1/auth/google', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      id_token: response.credential,
      device_id: deviceId,
      device_name: 'Web Browser',
      remember: true
    })
  });

  const data = await authResponse.json();

  if (authResponse.ok) {
    localStorage.setItem('access_token', data.access_token);
    localStorage.setItem('refresh_token', data.refresh_token);
    window.location.href = '/dashboard';
  } else {
    console.error('Login failed:', data.message);
  }
}
```

### Mobile App Integration

```javascript
// React Native example
async function googleSignIn() {
  try {
    const result = await GoogleSignin.signIn();
    const { idToken } = result;
    const deviceId = await AsyncStorage.getItem('device_id');

    const response = await fetch(API_URL + '/auth/google', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id_token: idToken,
        device_id: deviceId,
        device_name: 'Mobile App',
        remember: false
      })
    });

    const data = await response.json();
    await AsyncStorage.setItem('access_token', data.access_token);
    await AsyncStorage.setItem('refresh_token', data.refresh_token);
  } catch (error) {
    console.error('Google sign-in failed:', error);
  }
}
```

---

## Usage Notes

**OAuth Flow**:
- Requires Google Sign-In client library on frontend
- ID token must be valid and not expired
- Token validation occurs server-side via extrachill-users plugin

**Device Tracking**:
- `device_id` must be a valid UUID v4 string
- Used to track user sessions across multiple devices
- Allows selective logout from specific devices

**Token Management**:
- Access tokens typically expire in 1 hour
- Refresh tokens have longer expiry (configurable)
- Use refresh tokens to obtain new access tokens without re-login

**Account Creation**:
- If `from_join` is true, triggers registration flow
- Automatically creates user account from Google profile
- Profile image synced from Google account

**Related Endpoints**:
- [Auth Login](login.md) - Traditional username/password login
- [Auth Refresh](refresh.md) - Refresh expired access tokens
- [Auth Logout](logout.md) - Revoke tokens and end session
