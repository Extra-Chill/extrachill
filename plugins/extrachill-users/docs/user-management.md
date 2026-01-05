# User Management System

## Overview
Network-wide user management plugin providing authentication (including Google OAuth), profiles, onboarding, and avatar system across all Extra Chill sites.

## Authentication System
- Login/register/password reset UI via Gutenberg blocks
- **Google OAuth** via `POST /wp-json/extrachill/v1/auth/google` (ID token verified with RS256)
- **Token auth** for API clients (access token + refresh token via extrachill-api; this plugin issues/validates tokens)
- WordPress multisite cookies for cross-site access
- User creation runs through `apply_filters( 'extrachill_create_community_user', ... )` (single source of truth in `inc/core/user-creation.php`)
- Browser handoff (app → browser) uses a 60-second, single-use site transient token

## Google OAuth
- Server-side OAuth flow via `inc/oauth/google-service.php`
- RS256 ID token verification via `inc/oauth/jwt-rs256.php`
- Dedicated sign-in button with `assets/js/google-signin.js`
- Configuration stored in network options (via extrachill-multisite)
- REST API endpoint: `POST /wp-json/extrachill/v1/auth/google`

## User Onboarding
- Onboarding UI via Gutenberg block (`blocks/onboarding/`)
- Service layer at `inc/onboarding/service.php`
- REST API endpoints: `GET/POST /wp-json/extrachill/v1/users/onboarding`
- Tracks completion status in user meta (`onboarding_completed`, plus join-flow flag)

## Profile URL resolution
- `ec_get_user_profile_url( $user_id, $user_email = '' )` resolves in this order:
  1) community profile (`/u/{user_nicename}`)
  2) main-site author archive
  3) WordPress default author URL
- `ec_get_user_author_archive_url( $user_id )` always targets the main site.

## Badges + ranks
- Badges: `inc/badges/`
- Rank tiers: `inc/rank-system/` (rank is derived from `extrachill_total_points`)

## Avatar system
- Avatar display is provided by this plugin via `inc/avatar-display.php` (filters `pre_get_avatar`).
- Avatar upload UI lives in `extrachill-community` (bbPress profile edit integration).

## Avatar menu
- Rendered via `inc/avatar-menu.php` (hooks `extrachill_header_top_right` at priority 30).
- Canonical item builder: `extrachill_users_get_avatar_menu_items()` in `inc/avatar-menu-items.php`.
- Extensibility hook: `ec_avatar_menu_items` filter.

## Online tracking
- Online tracking uses `last_active` user meta and a 15-minute window (`inc/core/online-users.php`).

## Integration points
- Blog switching is used where the single source of truth lives on a different site (notably community + main-site author archives).
- OAuth client config is stored as network options (managed by extrachill-multisite).
- **Administrative Management**: User memberships (e.g., Lifetime Membership) and artist-user relationships are managed via the `extrachill-admin-tools` React interface, consuming REST endpoints from `extrachill-api`.
