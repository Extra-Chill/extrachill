# OAuth Configuration

REST API endpoint for retrieving OAuth provider configuration for client applications.

## Endpoint

### Get OAuth Configuration

**Endpoint**: `GET /wp-json/extrachill/v1/config/oauth`

**Purpose**: Return OAuth provider configuration including enabled providers and platform-specific client IDs for mobile apps.

**Parameters**: None

**Response** (HTTP 200):
```json
{
  "google": {
    "enabled": true,
    "web_client_id": "123456789-abcdefghijk.apps.googleusercontent.com",
    "ios_client_id": "123456789-ios.apps.googleusercontent.com",
    "android_client_id": "123456789-android.apps.googleusercontent.com"
  },
  "apple": {
    "enabled": false
  }
}
```

**Permission**: Public (no authentication required)

**File**: `inc/routes/config/oauth.php`

## Response Fields

- `google.enabled` - Whether Google OAuth is configured
- `google.web_client_id` - Google OAuth client ID for web clients
- `google.ios_client_id` - Google OAuth client ID for iOS apps
- `google.android_client_id` - Google OAuth client ID for Android apps
- `apple.enabled` - Whether Apple OAuth is configured

## Security Note

Client IDs are safe to expose publicly as they identify the application, not authenticate it. All OAuth flows require corresponding client secrets (not exposed) for server-side token validation.

## Integration

Used by web, iOS, and Android clients to discover which OAuth providers are available and obtain the appropriate client ID for authentication flows.
