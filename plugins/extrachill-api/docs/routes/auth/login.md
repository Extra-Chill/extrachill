# Auth Login

Authenticate a user and return JWT access/refresh tokens for API access. Supports device tracking for session management and optional cookie setting.

## Endpoints

### User Login

**Endpoint**: `POST /wp-json/extrachill/v1/auth/login`

**Purpose**: Authenticate user credentials and generate JWT tokens for API access.

**Permission**: Public (no authentication required)

**Parameters**:
- `identifier` (string, required) - Username or email address for login
- `password` (string, required) - User's password
- `device_id` (string, required) - UUID v4 device identifier for session tracking
- `device_name` (string, optional) - Human-readable device name (e.g., "Chrome on MacBook")
- `remember` (boolean, optional) - Whether to extend token expiry (default: false)
- `set_cookie` (boolean, optional) - Whether to set WordPress authentication cookie (default: false)

**Request Example**:
```json
{
  "identifier": "user@example.com",
  "password": "userpassword",
  "device_id": "550e8400-e29b-41d4-a716-446655440000",
  "device_name": "Chrome on MacBook Pro",
  "remember": true,
  "set_cookie": false
}
```

**Response** (HTTP 200):
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "id": 123,
    "username": "user",
    "email": "user@example.com",
    "display_name": "User Name"
  }
}
```

**Error Responses**:
- `400` - Missing credentials, invalid device_id format, or validation errors
- `401` - Invalid username/email or password
- `500` - Authentication service unavailable or dependency missing

**Implementation Details**:
- Validates device_id as UUID v4 format
- Calls `extrachill_users_login_with_tokens()` from extrachill-users plugin
- Supports both username and email login
- Device tracking for session management across multiple devices
- Optional cookie setting for traditional WordPress session

**File**: `inc/routes/auth/login.php`

---

## Usage Examples

### JavaScript Login

```javascript
async function loginUser(identifier, password, deviceId) {
  const response = await fetch('/wp-json/extrachill/v1/auth/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      identifier: identifier,
      password: password,
      device_id: deviceId,
      device_name: navigator.userAgent.substring(0, 50),
      remember: true
    })
  });

  const data = await response.json();

  if (response.ok) {
    // Store tokens securely
    localStorage.setItem('access_token', data.access_token);
    localStorage.setItem('refresh_token', data.refresh_token);
    return data.user;
  } else {
    throw new Error(data.message || 'Login failed');
  }
}
```

### Mobile App Login

```javascript
// Generate UUID v4 for device tracking
const deviceId = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
  const r = Math.random() * 16 | 0;
  const v = c == 'x' ? r : (r & 0x3 | 0x8);
  return v.toString(16);
});

const loginData = {
  identifier: email,
  password: password,
  device_id: deviceId,
  device_name: 'Mobile App iOS',
  remember: false
};
```

---

## Usage Notes

**Device Tracking**:
- `device_id` must be a valid UUID v4 string
- Used to track user sessions across multiple devices
- Allows selective logout from specific devices

**Token Management**:
- Access tokens typically expire in 1 hour
- Refresh tokens have longer expiry (configurable)
- Use refresh tokens to obtain new access tokens without re-login

**Security**:
- Passwords are validated server-side only
- Tokens should be stored securely (httpOnly cookies or secure storage)
- Device tracking helps prevent unauthorized access

**Integration**:
- Requires extrachill-users plugin for token functionality
- Works alongside WordPress traditional authentication
- Supports both API-first and cookie-based authentication flows

**Related Endpoints**:
- [Auth Refresh](refresh.md) - Refresh expired access tokens
- [Auth Register](register.md) - Create new user accounts</content>
<parameter name="filePath">docs/routes/auth/login.md