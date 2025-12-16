# Extra Chill Users

Network-activated user management system for the ExtraChill Platform. Handles user creation, authentication, team members, profile URLs, avatar menu, password reset, and the EC_Redirect_Handler-powered auth flow.

## Features

- User registration and login with Turnstile captcha via EC_Redirect_Handler orchestration
- Password reset functionality with email-based flow rendered by Gutenberg blocks
- Avatar upload and display system with bbPress integration and REST-compatible handlers
- Online users tracking across network with performance optimizations
- Team members management with manual override support
- Artist profile relationship functions (network-wide canonical)
- Author profile URL resolution with bidirectional linking
- Network-wide avatar menu with plugin extensibility via filter
- Ad-free license validation system
- Comment auto-approval for logged-in users
- Gutenberg blocks for login/register and password reset
- Newsletter subscription integration during registration
- Admin access control (wp-admin restriction for non-admins)

## Requirements

- WordPress Multisite
- Requires Plugins: extrachill-multisite, extrachill-api (REST API infrastructure)
- PHP 7.4+
- WordPress 5.0+

## Installation

1. Upload the plugin to your WordPress multisite network
2. Network activate the plugin
3. Configure settings as needed

## API Integration

This plugin integrates with the ExtraChill API (`extrachill-api`) plugin for user profile and authentication endpoints:

- **User Profiles** - `GET /wp-json/extrachill/v1/users/{id}` - Retrieve user profile data with permission-based field visibility
- **User Search** - `GET /wp-json/extrachill/v1/users/search` - Find users for mentions or admin management
- **User Leaderboard** - `GET /wp-json/extrachill/v1/users/leaderboard` - Rankings of users by points
- **User Artists** - `GET/POST/DELETE /wp-json/extrachill/v1/users/{id}/artists` - Manage user-artist relationships
- **Authentication** - `POST /wp-json/extrachill/v1/auth/{login,register,refresh}` - Token-based authentication system
- **Media Upload** - `POST /wp-json/extrachill/v1/media` - Avatar upload via unified media endpoint

See [AGENTS.md](AGENTS.md) for detailed development documentation.

## Development

See AGENTS.md and docs/CHANGELOG.md for detailed development documentation and version history.
