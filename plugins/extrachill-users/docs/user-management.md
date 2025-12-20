# User Management System

## Overview
Network-wide user management plugin providing authentication (including Google OAuth), profiles, onboarding, and avatar system across all Extra Chill sites.

## Authentication System
- Login/register/password reset via Gutenberg blocks
- **Google OAuth** integration with JWT RS256 validation
- **Bearer token authentication** for REST API access
- Multisite native authentication for cross-domain access
- Team member system with manual override support
- User creation on community.extrachill.com

## Google OAuth
- Server-side OAuth flow via `inc/oauth/google-service.php`
- JWT RS256 token validation via `inc/oauth/jwt-rs256.php`
- Dedicated sign-in button with `assets/js/google-signin.js`
- Configuration stored in network options (via extrachill-multisite)
- REST API endpoint: `POST /wp-json/extrachill/v1/auth/google`

## User Onboarding
- Guided onboarding experience via Gutenberg block (`blocks/onboarding/`)
- Service layer at `inc/onboarding/service.php`
- REST API endpoints: `GET/POST /wp-json/extrachill/v1/users/onboarding`
- Tracks completion status in user meta
- Integrates with artist platform for artist onboarding flows

## Profile System
- General profile URL resolution (community-first) via `ec_get_user_profile_url()`
- Main-site author archive URLs via `ec_get_user_author_archive_url()`
- Custom user fields and metadata
- Network-wide profile consistency
- **User badge and rank tier system** via `inc/badges/` and `inc/rank-system/`

## Avatar System
- Custom avatar functionality
- Network-wide avatar menu integration
- Online user tracking across all sites
- Avatar display in various contexts

## Integration Points
- Hooks into WordPress user system
- Cross-site data access using `switch_to_blog()` / `restore_current_blog()`
- Network options for global settings
- Function existence checks for safe cross-plugin calls
- OAuth configuration from extrachill-multisite plugin