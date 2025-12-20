# Unified Registration + Onboarding + Social Login

**Status**: Phase 1 Complete, Phase 2 Complete (Google OAuth), Phase 3 Pending (Apple OAuth + Mobile App)  
**Goal**: All users (email/password AND social login) go through the same onboarding flow to set username and preferences

## Implementation Status

### Completed (Phase 1 - Core Onboarding)
- ✅ Onboarding service layer (`extrachill-users/inc/onboarding/service.php`)
- ✅ Onboarding API endpoint (`extrachill-api/inc/routes/users/onboarding.php`)
- ✅ Auth `/me` response includes `onboarding_completed`
- ✅ Registration handler updated (auto-generates username from email)
- ✅ Registration API updated (redirects to /onboarding)
- ✅ User creation sets onboarding meta
- ✅ Registration form simplified (email + password only)
- ✅ Registration JS updated
- ✅ Registration emails updated
- ✅ Onboarding Gutenberg block (`extrachill-users/blocks/onboarding/`)

### Completed (Phase 2 - Google OAuth)
- ✅ Shared auth utilities (`extrachill-users/assets/js/auth-utils.js`)
- ✅ RS256 JWT verification (`extrachill-users/inc/oauth/jwt-rs256.php`)
- ✅ Google OAuth service (`extrachill-users/inc/oauth/google-service.php`)
- ✅ Google OAuth API endpoint (`extrachill-api/inc/routes/auth/google.php`)
- ✅ Google Sign-In frontend module (`extrachill-users/assets/js/google-signin.js`)
- ✅ Google Sign-In buttons in login/register block (both tabs)
- ✅ Social login CSS styles
- ✅ Onboarding block refactored to use ECAuthUtils

### Pending (Phase 3 - Apple OAuth)
- ⏳ Apple OAuth service (`extrachill-users/inc/oauth/apple-service.php`)
- ⏳ Apple OAuth API endpoint (`extrachill-api/inc/routes/auth/apple.php`)
- ⏳ Apple Sign-In button in login/register block

### Pending (Phase 5 - Mobile App)
- ⏳ App onboarding screen
- ⏳ App navigation guards
- ⏳ App Google Sign-In integration
- ⏳ App Apple Sign-In integration

## Overview

This plan implements:
1. Google OAuth login + Apple Sign-In (both required for App Store compliance)
2. Unified onboarding for ALL new users regardless of registration method
3. Username set during onboarding (not registration)
4. Artist/professional flags moved to onboarding
5. Dismissable onboarding with natural consequences (no nagging notices)
6. Account linking with user choice when email matches existing account

## Philosophy

**Onboarding is dismissable, not required.** Users can skip it, but they accept the consequences:

| Action | Username | Artist Platform | Forums | Future Username Changes |
|--------|----------|-----------------|--------|------------------------|
| Complete onboarding | Chosen by user | Full access (if artist/pro flag set) | Full access | Paid feature |
| Skip onboarding | Auto-generated | Blocked (no artist flag) | Full access | Paid feature |

**No nagging notices.** If they skip, they skip. Existing artist gates handle platform access. Spam prevention side effect: bots won't complete onboarding.

## Architecture

### Source of Truth Hierarchy

```
REST API (extrachill-api) ← Single source of truth
    ↓
Website (extrachill theme + plugins) ← Reference implementation
    ↓
Mobile App (extrachill-app) ← Mirrors website, consumes same API
```

### User Flow

```
┌─────────────────────────────────────────────────────────────┐
│  REGISTRATION                                               │
│                                                             │
│  Email/Password:              Google OAuth:                 │
│  - Email                      - OAuth token exchange        │
│  - Password                   - Email from Google           │
│  - (No username!)             - Display name from Google    │
│  - (No artist/pro checkboxes!)                              │
│                                                             │
│  User Created:                                              │
│  - Auto-generated username from email prefix or Google name │
│  - onboarding_completed = false                             │
│                                                             │
│  → Redirect to /onboarding                                  │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  ONBOARDING (/onboarding)                                   │
│                                                             │
│  Form Fields:                                               │
│  - Username (pre-filled with auto-generated, editable)      │
│  - "I love music" checkbox (checked, disabled - cheeky)     │
│  - "I am a musician" checkbox                               │
│  - "I work in the music industry" checkbox                  │
│                                                             │
│  Join Flow (/join → registration → /onboarding):            │
│  - At least one of artist/professional REQUIRED             │
│                                                             │
│  Regular Flow (/login → registration → /onboarding):        │
│  - Artist/professional checkboxes optional                  │
│                                                             │
│  User can:                                                  │
│  - Submit form → username set, flags set, redirect          │
│  - Navigate away → stuck with auto username, no flags       │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Username Auto-Generation

| Registration Method | Username Source | Example |
|---------------------|-----------------|---------|
| Email/Password | Email prefix | `john.smith@gmail.com` → `johnsmith` |
| Google OAuth | Display name | `John Smith` → `johnsmith` |

**Collision handling**: If `johnsmith` exists, try `johnsmith1`, `johnsmith2`, etc.

### Consequences of Skipping

Users who navigate away from `/onboarding` without completing it:

1. **Username stays** at auto-generated value
2. **No artist platform access** - existing `user_is_artist` gates block them
3. **Forums work normally** - no restrictions
4. **Can return to /onboarding later** - page stays accessible until completed
5. **No nagging notices** - we don't follow them around

### Returning to Onboarding

The `/onboarding` page remains accessible until `onboarding_completed = true`:
- Users can bookmark it and return later
- Once completed, page redirects to homepage
- No time limit on completion

### Artist Access Tab (Catch for Skipped Onboarding)

Users who skipped onboarding can still request artist platform access via:
`extrachill-community/inc/user-profiles/settings/tabs/artist-access-tab.php`

This tab handles three states:
1. **Has access** → Shows "Create Artist Profile" button
2. **Pending request** → Shows "Your request is pending admin review"
3. **No access** → Shows request form with artist/professional radio buttons

This provides a natural path for users who skipped onboarding to later request artist access without requiring them to complete the full onboarding flow.

---

## Implementation Tasks

### Phase 1: Core Infrastructure

#### 1.1 API Endpoints (extrachill-api)

**New File**: `extrachill-api/inc/routes/users/onboarding.php`

##### GET /extrachill/v1/users/onboarding

Returns current onboarding status and field values.

**Response**:
```json
{
  "completed": false,
  "from_join": false,
  "fields": {
    "username": "johnsmith",
    "user_is_artist": false,
    "user_is_professional": false
  }
}
```

**Logic**:
- Requires authentication
- If `onboarding_completed` meta doesn't exist → treat as completed (grandfathered users)
- If `onboarding_completed = false` → return current username and empty flags
- Include `from_join` flag to enforce artist/professional requirement

##### POST /extrachill/v1/users/onboarding

Completes onboarding.

**Payload**:
```json
{
  "username": "chosen-username",
  "user_is_artist": true,
  "user_is_professional": false
}
```

**Validation**:
- Username: required, unique, valid characters (alphanumeric, hyphens, underscores), 3-60 chars, not reserved
- If `from_join = true`: at least one of artist/professional required
- Artist/professional: booleans

**Logic**:
1. Validate username
2. Update `user_login` and `user_nicename`
3. Update `display_name` to match username
4. Set `user_is_artist` meta
5. Set `user_is_professional` meta
6. Set `onboarding_completed` = true
7. Set `onboarding_completed_at` = timestamp
8. Return success with redirect URL

**Response**:
```json
{
  "success": true,
  "user": {
    "id": 123,
    "username": "chosen-username",
    "user_is_artist": true,
    "user_is_professional": false
  },
  "redirect_url": "https://community.extrachill.com/"
}
```

---

#### 1.2 Service Layer (extrachill-users)

**New File**: `extrachill-users/inc/onboarding/service.php`

```php
/**
 * Generate username from email address.
 *
 * @param string $email Email address.
 * @return string Generated username with collision handling.
 */
function ec_generate_username_from_email( $email )

/**
 * Generate username from display name.
 *
 * @param string $display_name Display name (e.g., from Google).
 * @return string Generated username with collision handling.
 */
function ec_generate_username_from_name( $display_name )

/**
 * Get onboarding status for a user.
 *
 * @param int $user_id User ID.
 * @return array {completed: bool, from_join: bool, fields: array}
 */
function ec_get_onboarding_status( $user_id )

/**
 * Complete onboarding for a user.
 *
 * @param int   $user_id User ID.
 * @param array $data    {username: string, user_is_artist: bool, user_is_professional: bool}
 * @return array|WP_Error Success array or error.
 */
function ec_complete_onboarding( $user_id, $data )

/**
 * Check if user has completed onboarding.
 * Grandfathered users (no meta) return true.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function ec_is_onboarding_complete( $user_id )

/**
 * Validate username for onboarding.
 *
 * @param string $username  New username.
 * @param int    $user_id   Current user ID (to allow keeping same username).
 * @return true|WP_Error
 */
function ec_validate_onboarding_username( $username, $user_id )
```

##### User Meta Schema

| Key | Type | Purpose |
|-----|------|---------|
| `onboarding_completed` | bool | Whether user finished onboarding |
| `onboarding_completed_at` | int (timestamp) | When they completed it |
| `onboarding_from_join` | bool | Whether user came from /join flow |
| `user_is_artist` | bool | Artist account status |
| `user_is_professional` | bool | Industry professional status |

**Grandfathering**: Users without `onboarding_completed` meta are treated as completed.

---

#### 1.3 Auth Response Update (extrachill-api)

**File**: `extrachill-api/inc/routes/auth/me.php`

Add `onboarding_completed` to response:

```json
{
  "id": 123,
  "username": "johnsmith",
  "email": "john@example.com",
  "display_name": "johnsmith",
  "avatar_url": "...",
  "profile_url": "...",
  "registered": "2024-01-01T00:00:00",
  "onboarding_completed": false
}
```

---

### Phase 2: Registration Flow Updates

#### 2.1 Update Registration Form (extrachill-users)

**File**: `extrachill-users/blocks/login-register/render.php`

**Changes**:
- Remove `username` field from registration form
- Remove artist/professional checkboxes
- Keep only: Email, Password, Password Confirm
- Add Google OAuth button

**Remove**:
- Username input field (line ~132-133)
- `registration-user-types` div with checkboxes (lines ~144-154)

**Add**:
- Google Sign-In button after form or in social login section

#### 2.2 Update Registration JavaScript

**File**: `extrachill-users/blocks/login-register/view.js`

**Changes**:
- Remove username from form data collection
- Remove `user_is_artist` and `user_is_professional` from payload
- Remove validation that requires artist/professional selection for join flow
- Add Google Sign-In handler

#### 2.3 Update Registration Handler

**File**: `extrachill-users/inc/auth/register.php`

**Changes**:
- Remove `username` from required fields
- Remove `user_is_artist` and `user_is_professional` handling
- Generate username using `ec_generate_username_from_email()`
- Track `from_join` in user meta for onboarding enforcement

#### 2.4 Update Registration API

**File**: `extrachill-api/inc/routes/auth/register.php`

**Changes**:
- Remove `username` from required params
- Remove `user_is_artist` and `user_is_professional` params
- Generate username using `ec_generate_username_from_email()`
- Set `onboarding_completed` = false
- Set `onboarding_from_join` if from join flow
- Change redirect to `/onboarding`

#### 2.5 Update User Creation

**File**: `extrachill-users/inc/core/user-creation.php`

**Function**: `ec_multisite_create_community_user()`

**Changes**:
- Accept auto-generated username
- Set `onboarding_completed` = false
- Set `onboarding_from_join` if applicable
- Remove `user_is_artist` and `user_is_professional` meta setting (moved to onboarding)

#### 2.6 Update Registration Emails

**File**: `extrachill-users/inc/core/registration-emails.php`

**Changes to admin notification**:
- Remove "Artist: Yes/No" and "Professional: Yes/No" lines
- Add "Onboarding: Pending" status

**Welcome email**: No changes needed (doesn't mention username or artist status)

---

### Phase 3: Onboarding Block

#### 3.1 Block Structure

**New Directory**: `extrachill-users/blocks/onboarding/`

```
blocks/onboarding/
├── block.json
├── render.php
├── view.js
└── style.css
```

#### 3.2 block.json

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 2,
  "name": "extrachill/onboarding",
  "title": "User Onboarding",
  "category": "widgets",
  "icon": "admin-users",
  "description": "User onboarding form for new Extra Chill members",
  "attributes": {
    "redirectUrl": {
      "type": "string",
      "default": ""
    }
  },
  "supports": {
    "html": false,
    "multiple": false
  },
  "textdomain": "extrachill-users",
  "render": "file:./render.php",
  "style": "file:./style.css",
  "viewScript": "file:./view.js"
}
```

#### 3.3 render.php

```php
<?php
/**
 * Onboarding Block - Server-Side Render
 *
 * Minimal container for vanilla JS app. Handles redirects for edge cases.
 */

// Not logged in → login page
if ( ! is_user_logged_in() ) {
    wp_safe_redirect( ec_get_site_url( 'community' ) . '/login/' );
    exit;
}

// Already completed → homepage
if ( function_exists( 'ec_is_onboarding_complete' ) 
     && ec_is_onboarding_complete( get_current_user_id() ) ) {
    wp_safe_redirect( ec_get_site_url( 'community' ) );
    exit;
}

// Get stored redirect URL (from registration) or default
$stored_redirect = get_user_meta( get_current_user_id(), 'onboarding_redirect_url', true );
$redirect_url = $stored_redirect ?: ec_get_site_url( 'community' );
$from_join = get_user_meta( get_current_user_id(), 'onboarding_from_join', true ) === '1';
?>

<div 
    id="extrachill-onboarding-root" 
    data-redirect-url="<?php echo esc_attr( $redirect_url ); ?>"
    data-from-join="<?php echo $from_join ? 'true' : 'false'; ?>"
    data-rest-url="<?php echo esc_url( rest_url( 'extrachill/v1/' ) ); ?>"
    data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
>
    <div class="onboarding-loading">Loading...</div>
</div>
```

#### 3.4 view.js

Vanilla JS app (no build step):
- Fetches current status from GET /users/onboarding
- Renders form with:
  - Username input (pre-filled, editable)
  - "I love music" checkbox (checked, disabled)
  - "I am a musician" checkbox
  - "I work in the music industry" checkbox
- If `from_join = true`, validate at least one of artist/professional is checked
- Submits to POST /users/onboarding
- Redirects on success

---

### Phase 4: Google + Apple OAuth

#### 4.1 Account Linking Behavior

**Auto-linking**: When a user attempts social login with an email that matches an existing account, the OAuth provider is automatically linked to that account.

| Scenario | Behavior |
|----------|----------|
| User exists with matching `google_user_id` | Log them in |
| User exists with matching email (no `google_user_id`) | Link Google to account, log them in |
| No user exists | Create new user, set `onboarding_completed = false` |

This provides the simplest UX for the vast majority of users. No confirmation modal needed.

#### 4.2 API Endpoints

**New File**: `extrachill-api/inc/routes/auth/google.php`

##### POST /extrachill/v1/auth/google

Exchanges Google ID token for WordPress authentication.

**Payload**:
```json
{
  "id_token": "google_id_token_here",
  "device_id": "uuid-v4",
  "device_name": "Web",
  "from_join": false,
  "set_cookie": true
}
```

**Logic**:
1. Verify Google ID token using RS256 JWT verification against Google's JWKS
2. Extract email, display name, and Google user ID (sub claim)
3. Check if user exists with Google ID linked → log them in
4. Check if user exists with this email (not linked) → auto-link Google and log them in
5. If new user: Create with auto-generated username from display name, set `onboarding_completed = false`
6. Generate auth tokens via existing token service
7. Return auth tokens + user data + `redirect_url`

**Response** (new user → redirect to onboarding):
```json
{
  "success": true,
  "user": {
    "id": 123,
    "username": "johnsmith",
    "email": "john@gmail.com",
    "onboarding_completed": false
  },
  "access_token": "...",
  "access_expires_at": "...",
  "refresh_token": "...",
  "refresh_expires_at": "...",
  "redirect_url": "https://community.extrachill.com/onboarding/"
}
```

**Response** (existing user → redirect to destination):
```json
{
  "success": true,
  "user": {
    "id": 123,
    "username": "john-smith",
    "email": "john@gmail.com",
    "onboarding_completed": true
  },
  "access_token": "...",
  "access_expires_at": "...",
  "refresh_token": "...",
  "refresh_expires_at": "...",
  "redirect_url": "https://community.extrachill.com/"
}
```

**New File**: `extrachill-api/inc/routes/auth/apple.php` (Phase 3)

Same pattern as Google, but:
- Verifies Apple identity token using Apple's JWKS
- Apple only provides email on first login (must be stored)
- Uses `apple_user_id` meta instead of `google_user_id`

#### 4.3 OAuth Service Layer

**New Directory**: `extrachill-users/inc/oauth/`

**New File**: `extrachill-users/inc/oauth/jwt-rs256.php`

RS256 JWT verification using public keys from JWKS endpoints. Caches JWKS using the `Cache-Control` header from the provider's response.

```php
/**
 * Verify RS256 JWT using JWKS.
 *
 * @param string $token    JWT token.
 * @param string $jwks_url JWKS endpoint URL.
 * @param string $audience Expected audience (aud claim).
 * @param string $issuer   Expected issuer (iss claim).
 * @return array|WP_Error Decoded payload or error.
 */
function ec_verify_rs256_jwt( $token, $jwks_url, $audience, $issuer )

/**
 * Fetch and cache JWKS from URL.
 * Cache TTL is parsed from the provider's Cache-Control header.
 *
 * @param string $jwks_url JWKS endpoint.
 * @return array|WP_Error Array of keys or error.
 */
function ec_fetch_jwks( $jwks_url )

/**
 * Convert JWK RSA key to PEM format.
 *
 * @param array $jwk JWK key data with 'n' and 'e' components.
 * @return string|false PEM string or false on failure.
 */
function ec_jwk_to_pem( $jwk )
```

**New File**: `extrachill-users/inc/oauth/google-service.php`

```php
/**
 * Verify Google ID token and extract user info.
 *
 * @param string $id_token Google ID token.
 * @return array|WP_Error {email: string, name: string, google_id: string, picture: string}
 */
function ec_verify_google_token( $id_token )

/**
 * Find or create user from Google OAuth.
 * Auto-links if email matches existing account.
 *
 * @param array $google_user {email, name, google_id, picture}
 * @param bool  $from_join   Whether user came from /join flow.
 * @return array|WP_Error {user_id: int, is_new: bool, user: WP_User}
 */
function ec_oauth_google_user( $google_user, $from_join = false )

/**
 * Link Google account to existing user.
 *
 * @param int    $user_id   Existing user ID.
 * @param string $google_id Google user ID (sub claim).
 * @return bool Success.
 */
function ec_link_google_account( $user_id, $google_id )

/**
 * Find user by Google ID.
 *
 * @param string $google_id Google user ID (sub claim).
 * @return WP_User|false User object or false.
 */
function ec_get_user_by_google_id( $google_id )
```

**New File**: `extrachill-users/inc/oauth/apple-service.php` (Phase 3)

Same pattern as google-service.php with Apple-specific constants and meta keys.

#### 4.4 User Meta Schema (OAuth)

| Key | Type | Purpose |
|-----|------|---------|
| `google_user_id` | string | Google unique user identifier (sub claim) |
| `apple_user_id` | string | Apple unique user identifier (Phase 3) |
| `oauth_linked_at` | int (timestamp) | When OAuth was linked |

#### 4.5 Login Block Updates

**File**: `extrachill-users/blocks/login-register/render.php`

Add social login buttons to both login and register tabs (Google Sign-In handles both flows):

```html
<div class="social-login-divider">
    <span>or</span>
</div>
<div class="social-login-buttons">
    <div id="google-signin-btn" class="google-signin-button"></div>
</div>
```

Google Identity Services renders its own styled button. Apple button will be added in Phase 3.

**New File**: `extrachill-users/assets/js/google-signin.js`

Integrates Google Identity Services (GIS) library. Uses ECAuthUtils for shared functionality.

**File**: `extrachill-users/blocks/login-register/view.js`

No changes needed - Google Sign-In is handled by separate `google-signin.js` module.

#### 4.6 API Configuration

**Google OAuth**:
- Google Cloud Console project with OAuth 2.0 credentials
- Authorized JavaScript origins: `*.extrachill.com`
- Configured via: Network Admin > Extra Chill Multisite > OAuth
- Stored in: `get_site_option('extrachill_google_client_id')`, `get_site_option('extrachill_google_client_secret')`

**Apple Sign-In**:
- Apple Developer account with Sign In with Apple enabled
- Services ID configured for web
- Private key for server-side verification
- Configured via: Network Admin > Extra Chill Multisite > OAuth
- Stored in site options:
  - `extrachill_apple_client_id` (Services ID)
  - `extrachill_apple_team_id`
  - `extrachill_apple_key_id`
  - `extrachill_apple_private_key`

---

### Phase 5: Mobile App Updates

#### 5.1 API Client

**File**: `src/api/client.ts`

```typescript
// Onboarding
async getOnboardingStatus(): Promise<OnboardingStatus>
async completeOnboarding(data: OnboardingData): Promise<OnboardingResult>

// Google OAuth
async authenticateWithGoogle(idToken: string, fromJoin?: boolean): Promise<AuthResult>
```

#### 5.2 Auth Context

**File**: `src/auth/context.tsx`

- Add `onboardingCompleted` to state
- Update from `/auth/me` response
- Add Google sign-in method

#### 5.3 Onboarding Screen

**New File**: `app/onboarding.tsx`

- Form matching web UI
- Submit to same API endpoint
- Navigate to feed on success

#### 5.4 Navigation Guards

**File**: `app/(drawer)/_layout.tsx`

```typescript
if (isAuthenticated && !onboardingCompleted) {
  return <Redirect href="/onboarding" />;
}
```

#### 5.5 Google Sign-In

Use `expo-auth-session` for Google OAuth:
- Configure with same Client ID
- Exchange token with /auth/google endpoint
- Handle onboarding redirect

---

## File Changes Summary

### New Files

| File | Purpose | Status |
|------|---------|--------|
| `extrachill-api/inc/routes/users/onboarding.php` | Onboarding REST endpoint | ✅ Done |
| `extrachill-api/inc/routes/auth/google.php` | Google OAuth REST endpoint | ✅ Done |
| `extrachill-api/inc/routes/auth/apple.php` | Apple OAuth REST endpoint | ⏳ Pending |
| `extrachill-users/inc/onboarding/service.php` | Onboarding service functions | ✅ Done |
| `extrachill-users/inc/oauth/jwt-rs256.php` | RS256 JWT verification | ✅ Done |
| `extrachill-users/inc/oauth/google-service.php` | Google OAuth service | ✅ Done |
| `extrachill-users/inc/oauth/apple-service.php` | Apple OAuth service | ⏳ Pending |
| `extrachill-users/assets/js/google-signin.js` | Google Sign-In frontend module | ✅ Done |
| `extrachill-users/assets/js/auth-utils.js` | Shared auth utilities | ✅ Done |
| `extrachill-users/blocks/onboarding/` | Onboarding Gutenberg block | ✅ Done |
| `extrachill-multisite/admin/network-payments-settings.php` | Stripe keys admin UI | ✅ Done |
| `extrachill-multisite/admin/network-oauth-settings.php` | OAuth keys admin UI | ✅ Done |
| `extrachill-app/app/onboarding.tsx` | App onboarding screen | ⏳ Pending |

### Modified Files

| File | Changes | Status |
|------|---------|--------|
| `extrachill-users/blocks/login-register/render.php` | Remove username + checkboxes | ✅ Done |
| `extrachill-users/blocks/login-register/view.js` | Remove username + checkboxes from payload | ✅ Done |
| `extrachill-users/inc/auth/register.php` | Remove username/flags, generate username, set onboarding meta | ✅ Done |
| `extrachill-users/inc/core/user-creation.php` | Accept auto-generated username, set onboarding meta | ✅ Done |
| `extrachill-users/inc/core/registration-emails.php` | Update admin email (remove artist/pro, add onboarding status) | ✅ Done |
| `extrachill-api/inc/routes/auth/register.php` | Remove username requirement, redirect to onboarding | ✅ Done |
| `extrachill-api/inc/routes/auth/me.php` | Add onboarding_completed to response | ✅ Done |
| `extrachill-users/inc/auth-tokens/service.php` | Update registration to generate username, set onboarding meta | ✅ Done |
| `extrachill-users/blocks/login-register/render.php` | Add Google button to both tabs | ✅ Done |
| `extrachill-users/blocks/login-register/style.css` | Add social login styles | ✅ Done |
| `extrachill-users/blocks/onboarding/render.php` | Enqueue auth-utils script | ✅ Done |
| `extrachill-users/blocks/onboarding/view.js` | Refactor to use ECAuthUtils | ✅ Done |
| `extrachill-users/extrachill-users.php` | Load oauth files | ✅ Done |
| `extrachill-app/src/api/client.ts` | Add onboarding + Google methods | ⏳ Pending |
| `extrachill-app/src/auth/context.tsx` | Add onboardingCompleted state | ⏳ Pending |
| `extrachill-app/app/(drawer)/_layout.tsx` | Add onboarding navigation guard | ⏳ Pending |

---

## Existing Users (Grandfathering)

Users registered before this system:
- Have no `onboarding_completed` meta
- Treated as `onboarding_completed = true` (grandfathered)
- Already have usernames set
- Already have (or don't have) artist/professional flags
- Can visit `/onboarding` page → redirects to homepage (already completed)

---

## Testing Checklist

### Registration (Email/Password) - Ready to Test
- [ ] Form shows only Email + Password (no username, no checkboxes)
- [ ] User created with auto-generated username from email
- [ ] User has `onboarding_completed = false`
- [ ] Redirects to /onboarding after registration
- [ ] Welcome email still sends correctly

### Registration (Google OAuth) - Ready to Test
- [ ] Google button appears on both login and register tabs
- [ ] Google Identity Services popup works correctly
- [ ] New user created with username from Google display name
- [ ] Existing user with matching email is auto-linked and logged in
- [ ] Existing user with linked Google ID logs in normally
- [ ] Redirects to /onboarding for new users
- [ ] Redirects to destination for existing users

### Onboarding Page - Ready to Test
- [ ] Not logged in → redirects to login
- [ ] Already completed → redirects to homepage
- [ ] Form shows current username (editable)
- [ ] "I love music" checkbox checked and disabled
- [ ] Can change username to valid unique value
- [ ] Can check artist/professional boxes
- [ ] From /join flow: requires at least one of artist/professional
- [ ] Regular flow: artist/professional optional
- [ ] Submit updates all user data
- [ ] Submit sets onboarding_completed = true
- [ ] Redirects to stored destination after submit

### Skipping Onboarding - Ready to Test
- [ ] User can navigate away from /onboarding
- [ ] No notices follow them around
- [ ] Username stays as auto-generated value
- [ ] Can return to /onboarding later
- [ ] Artist platform gates still block them (no artist/pro flag)

### API - Ready to Test
- [ ] GET /users/onboarding returns correct status
- [ ] POST /users/onboarding validates username
- [ ] POST /users/onboarding enforces artist/pro for join flow
- [ ] POST /users/onboarding updates user correctly
- [ ] GET /auth/me includes onboarding_completed
- [ ] POST /auth/google creates/authenticates user

### Mobile App - Not Yet Implemented
- [ ] Auth state includes onboardingCompleted
- [ ] Incomplete users see onboarding screen
- [ ] Google sign-in works via expo-auth-session
- [ ] Onboarding form submits correctly
- [ ] Join flow enforces artist/pro selection

---

## Foundational Infrastructure (Complete)

### API Key Storage Pattern

All API keys are now stored via Network Admin UI using `get_site_option()`:

| Service | Admin Page | Site Options |
|---------|-----------|--------------|
| Turnstile | Security | `extrachill_turnstile_site_key`, `extrachill_turnstile_secret_key` |
| Stripe | Payments | `extrachill_stripe_secret_key`, `extrachill_stripe_publishable_key`, `extrachill_stripe_webhook_secret` |
| Google OAuth | OAuth | `extrachill_google_client_id`, `extrachill_google_client_secret` |
| Apple OAuth | OAuth | `extrachill_apple_client_id`, `extrachill_apple_team_id`, `extrachill_apple_key_id`, `extrachill_apple_private_key` |
| AI Providers | AI Client | Managed via `chubes_ai_provider_api_keys` filter |

All services support filter overrides for local development (used by extrachill-dev plugin for Stripe/Turnstile).

### Network Admin Menu Structure

```
Extra Chill Multisite (priority 5)
├── ExtraChill Security (Turnstile)
├── Payments (Stripe)
├── OAuth (Google + Apple)
└── AI Client (from ai-client plugin)
```

---

## Future Considerations

### Paid Username Changes
- After onboarding, username is locked
- Future feature: Pay $X to unlock username change
- `onboarding_completed_at` timestamp tracks when they had the opportunity
