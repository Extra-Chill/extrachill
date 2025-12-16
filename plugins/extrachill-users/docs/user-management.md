# User Management System

## Overview
Network-wide user management plugin providing authentication, profiles, and avatar system across all Extra Chill sites.

## Authentication System
- Login/register/password reset via Gutenberg blocks
- Multisite native authentication for cross-domain access
- Team member system with manual override support
- User creation on community.extrachill.com

## Profile System
- General profile URL resolution (community-first) via `ec_get_user_profile_url()`
- Main-site author archive URLs via `ec_get_user_author_archive_url()`
- Custom user fields and metadata
- Network-wide profile consistency

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