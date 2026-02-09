# Extra Chill Users

Network-wide user management for the Extra Chill platform.

## What It Does

Extra Chill Users handles everything about user identity across the network:

- **Authentication** — Login, registration, Google OAuth, token-based API auth
- **Profiles** — Avatars, badges, ranks, artist relationships
- **Onboarding** — Guided first-time user experience
- **Membership** — Lifetime membership validation (ad-free benefit)

## How It Works

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   SIGNUP    │ ──▶ │  ONBOARD    │ ──▶ │   ACCESS    │
│ Login/OAuth │     │  Profile,   │     │ All network │
│ Turnstile   │     │  Avatar     │     │   sites     │
└─────────────┘     └─────────────┘     └─────────────┘
```

Users sign up once and get access to all Extra Chill network sites with a unified identity.

## Features

| Feature | Description |
|---------|-------------|
| **Google OAuth** | One-click sign-in with RS256 token verification |
| **Token Auth** | Bearer tokens for REST API access (mobile app, headless) |
| **User Onboarding** | Guided first-time setup via Gutenberg block |
| **Avatar System** | Upload and display with bbPress integration |
| **Badge & Ranks** | Tier system based on community participation |
| **Membership** | Lifetime membership with ad-free benefit |

## Auth Flow

1. **Registration** — Email/password or Google OAuth with Turnstile captcha
2. **Login** — Returns access + refresh tokens for API clients
3. **Refresh** — Token refresh for long-lived sessions
4. **Cross-Domain** — Network-wide authentication via multisite

## REST API

All auth endpoints live in `extrachill-api` under `extrachill/v1`:

| Endpoint | Purpose |
|----------|---------|
| `POST /auth/login` | User login, returns tokens |
| `POST /auth/register` | User registration |
| `POST /auth/google` | Google OAuth authentication |
| `POST /auth/refresh` | Refresh access token |
| `POST /auth/logout` | Logout and token revocation |
| `GET /auth/me` | Current authenticated user |

## Requirements

- WordPress Multisite
- PHP 7.4+
- WordPress 5.0+
- Requires: `extrachill-multisite`, `extrachill-api`

## Development

See [AGENTS.md](AGENTS.md) for technical implementation details.

```bash
# Build blocks
npm run build

# Package for distribution
./build.sh
```

## Documentation

- [AGENTS.md](AGENTS.md) — Technical reference for contributors
- [docs/CHANGELOG.md](docs/CHANGELOG.md) — Version history
