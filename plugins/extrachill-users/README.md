# Extra Chill Users

Network-activated user management system for the ExtraChill Platform. Handles user creation, authentication, team members, profile URLs, avatar menu, password reset, and the EC_Redirect_Handler-powered auth flow introduced in v0.2.0.

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
- Gutenberg blocks for login/register and password reset (login + reset flows render blocks directly)
- Newsletter subscription integration during registration
- Admin access control (wp-admin restriction for non-admins)

## Requirements

- WordPress Multisite
- Requires Plugins: extrachill-multisite
- PHP 7.4+
- WordPress 5.0+

## Installation

1. Upload the plugin to your WordPress multisite network
2. Network activate the plugin
3. Configure settings as needed

## Development

See AGENTS.md and docs/CHANGELOG.md for detailed development documentation and the 0.2.0 version reset rationale.
