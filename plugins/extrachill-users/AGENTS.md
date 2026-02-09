# AGENTS.md — Technical Reference

Technical implementation details for AI coding assistants and contributors.

## Architecture Overview

Network-activated plugin providing user management across all Extra Chill multisite network sites.

## Authentication System

### EC_Redirect_Handler
Orchestrates the auth flow for registration and login with Turnstile captcha integration.

### Google OAuth
- Dedicated sign-in button component
- RS256 ID token verification
- Endpoint: `POST /wp-json/extrachill/v1/auth/google`

### Token-Based Auth
Bearer tokens for REST API access (used by mobile app and headless clients).

**Token Flow:**
1. Login returns access token + refresh token
2. Access token used in Authorization header
3. Refresh token exchanges for new access token
4. Logout revokes all tokens

### Refresh Token Storage
Device-based authentication stores refresh tokens in a network-wide custom table:

- **Table**: `{base_prefix}extrachill_refresh_tokens`
- **Helper**: `extrachill_users_refresh_token_table_name()`
- **Schema/installer**: `inc/auth-tokens/db.php`
- **Creation**: On activation via `inc/core/activation.php`, also ensured on `admin_init`

## REST API Endpoints

All endpoints registered in `extrachill-api` plugin under `extrachill/v1` namespace:

### Authentication (7 endpoints)
- `POST /auth/login` — User login returning access + refresh tokens
- `POST /auth/register` — User registration
- `POST /auth/google` — Google OAuth authentication
- `POST /auth/refresh` — Refresh access tokens
- `POST /auth/logout` — Logout and token revocation
- `GET /auth/me` — Get current authenticated user
- `POST /auth/browser-handoff` — Browser handoff for cross-device auth

### User Management
- `GET /users/{id}` — User profile with permission-based field visibility
- `GET /users/search` — Find users for mentions or admin
- `GET /users/leaderboard` — Rankings by points
- `GET|POST|DELETE /users/{id}/artists` — Manage user-artist relationships
- `GET|POST /users/onboarding` — Onboarding flow and status

### Media
- `POST /media` — Avatar upload via unified media endpoint

## User Features

### Avatar System
- Upload and display with bbPress integration
- REST-compatible handlers
- Network-wide avatar menu with plugin extensibility via filter

### Badge & Rank System
- Tier system based on community participation
- Points-based leaderboard

### Online Users Tracking
Network-wide tracking with performance optimizations.

### Team Members
Management with manual override support.

### Artist Profile Relationships
Network-wide canonical artist-user linking with bidirectional resolution.

## Membership System

### Lifetime Membership
- Stored in user meta: `extrachill_lifetime_membership`
- Provides ad-free benefit across network
- Validation: `is_user_lifetime_member()`
- Creation: `ec_create_lifetime_membership()` (called by shop plugin)

### Ad-Free License
Validation system checks membership status for ad display logic.

## Gutenberg Blocks

- **Login/Register Block** — Auth forms with Turnstile
- **Password Reset Block** — Email-based reset flow
- **User Onboarding Block** — Guided first-time experience

## Key Functions

| Function | Purpose |
|----------|---------|
| `is_user_lifetime_member()` | Check membership status |
| `ec_create_lifetime_membership()` | Create membership from purchase |
| `extrachill_users_refresh_token_table_name()` | Get refresh token table name |

## Security Features

- **Turnstile Captcha** — Registration and login protection
- **Admin Access Control** — wp-admin restriction for non-admins
- **Comment Auto-Approval** — Logged-in users bypass moderation

## Integration Points

### With extrachill-api
All REST endpoints registered there, this plugin provides:
- Auth logic and token management
- User data functions
- Permission callbacks

### With extrachill-shop
- `ec_create_lifetime_membership()` called on membership purchase
- `is_user_lifetime_member()` used for ad-free checks

### With extrachill-community
- bbPress avatar integration
- Badge display in forums
- Online users tracking

## Project Structure

```
extrachill-users/
├── extrachill-users.php        # Main plugin file
├── inc/
│   ├── core/                   # Activation, bootstrap
│   ├── auth-tokens/            # Token storage, refresh logic
│   ├── avatar/                 # Avatar upload and display
│   ├── badges/                 # Badge and rank system
│   ├── membership/             # Lifetime membership
│   ├── onboarding/             # First-time user flow
│   ├── team-members/           # Team member management
│   └── blocks/                 # Gutenberg blocks
└── build.sh                    # Production packaging
```
