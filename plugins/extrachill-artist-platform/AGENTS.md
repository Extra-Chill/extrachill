# AGENTS.md — Technical Reference

Technical implementation details for AI coding assistants and contributors.

## Architecture Overview

- **Plugin Core**: `ExtraChillArtistPlatform` bootstraps the plugin, `ExtraChillArtistPlatform_PageTemplates` manages template routing, and `ExtraChillArtistPlatform_Assets` enqueues CSS/JS based on context.
- **Data Systems**: `ec_get_link_page_data()` and `ec_get_artist_profile_data()` deliver unified data structures (links, CSS vars, socials, advanced settings).
- **Permissions**: Centralized in `inc/core/filters/permissions.php` via `ec_can_manage_artist()` and `ec_can_manage_link_page()`.

## Join Flow System

Entry via `extrachill.link/join` redirects through `sunrise.php` to `artist.extrachill.com/login/?from_join=true`.

**Key Functions:**
- `ec_render_join_flow_modal()` — Renders join modal when `from_join` flag present
- `ec_join_flow_login_redirect()` — Routes authenticated users after login
- `ec_join_flow_registration_redirect()` — Routes users after registration
- `ec_get_join_flow_destination()` — Determines destination (`/create-artist/` or `/manage-link-page/`)

## Gutenberg Blocks

All blocks live in `src/blocks/` and share a REST API client at `src/blocks/shared/api/client.js`.

### Link Page Editor (`src/blocks/link-page-editor/`)
- Tabbed interface: Info, Links, Customize, Advanced, Socials
- dnd-kit for drag-and-drop reordering
- PreviewContext for live preview
- CSS custom properties for theming
- QR code generation
- Advanced settings: tracking, redirects, expiration

### Artist Analytics (`src/blocks/artist-analytics/`)
- Chart.js dashboard
- Artist switcher for multi-artist users
- Date filtering
- REST-driven data via `inc/database/artist-analytics-db.php`

### Artist Manager (`src/blocks/artist-manager/`)
- Profile info and socials editing
- Roster member management (invitations, removals)
- Subscriber exports
- REST API hooks for all operations

### Artist Creator (`src/blocks/artist-creator/`)
- Guided profile creation flow
- Creates `artist_profile` post and companion link page
- Routes from join flow

### Artist Shop Manager (`src/blocks/artist-shop-manager/`)
- Full product CRUD
- Image uploads
- Inventory tracking
- Order fulfillment
- Shipping label purchasing (Shippo)
- Stripe Connect payment integration

## Link Pages

Live pages render on `extrachill.link` through:
- **Template**: `inc/link-pages/live/templates/extrch-link-page-template.php`
- **Data**: `ec_get_link_page_data()` provides all link page data
- **Features**: Analytics tracking, subscription modals, share tools, permission-aware edit button

## Analytics & Subscriptions

### Database Tables
- `{prefix}_extrch_link_page_daily_views` — Daily view aggregation
- `{prefix}_extrch_link_page_daily_link_clicks` — Daily click aggregation
- `{prefix}_artist_subscribers` — Email subscribers

### Handlers
- `inc/link-pages/live/analytics.php` — Analytics endpoints
- `inc/link-pages/live/assets/js/link-page-subscribe.js` — Subscription form handling

## Roster & Permissions

Roster system lives in `inc/artist-profiles/roster/`:
- Invitation management
- Member management
- Permission delegation

Permission filters in `inc/core/filters/permissions.php`:
- `ec_can_manage_artist()` — Artist profile access
- `ec_can_manage_link_page()` — Link page access
- `ec_can_create_artist_profiles()` — Profile creation access

## Data Filters

Extensibility filters for other plugins:
- `extrch_get_link_page_data` — Inject/modify link page data
- `extrch_get_link_page_analytics` — Inject/modify analytics data

## REST API

All blocks call `/wp-json/extrachill/v1/*` endpoints with nonces. Key endpoint groups:
- `/artists/{id}` — Artist profile CRUD
- `/artists/{id}/links` — Link page data
- `/artists/{id}/analytics` — Analytics data
- `/artists/{id}/subscribers` — Subscriber management
- `/artists/{id}/roster` — Roster management

## Templates

| Location | Purpose |
|----------|---------|
| `inc/link-pages/live/templates/` | Public link page templates |
| `inc/artist-profiles/frontend/templates/` | Artist profile templates |
| `inc/home/templates/` | Homepage components |
| `inc/core/templates/` | Shared components |

Template rendering uses `ec_render_template()` for modular sections.

## Project Structure

```
extrachill-artist-platform/
├── extrachill-artist-platform.php  # Main plugin file
├── inc/
│   ├── core/                       # Bootstrap, assets, templates, permissions
│   ├── artist-profiles/            # Profile management, roster, frontend
│   ├── link-pages/                 # Link page data, live templates, analytics
│   ├── join/                       # Join flow handling
│   ├── home/                       # Homepage components
│   └── database/                   # Database helpers
├── src/blocks/                     # Gutenberg block source
│   ├── link-page-editor/
│   ├── artist-analytics/
│   ├── artist-manager/
│   ├── artist-creator/
│   ├── artist-shop-manager/
│   └── shared/                     # Shared API client, utilities
└── build.sh                        # Production packaging
```

## Build System

- Blocks compile via `@wordpress/scripts` and `webpack.config.js`
- `./build.sh` symlinks to shared `../../.github/build.sh` pipeline
- Output: `/build/extrachill-artist-platform.zip`
- Respects `.buildignore` for exclusions
