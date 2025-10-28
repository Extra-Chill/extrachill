# Extra Chill Users

Network-activated user management system for the ExtraChill Platform. Handles user creation, authentication, team members, profile URLs, avatar menu, and password reset.

## Features

- User registration and login with Turnstile captcha
- Password reset functionality with email-based flow
- Avatar upload and display system with bbPress integration
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
- Requires Plugins: extrachill-multisite
- PHP 7.4+
- WordPress 5.0+

## Installation

1. Upload the plugin to your WordPress multisite network
2. Network activate the plugin
3. Configure settings as needed

## Development

See CLAUDE.md for detailed development documentation.