# Extra Chill Artist Platform

Extra Chill Artist Platform is a WordPress plugin that powers artist profiles, membership rosters, and branded link pages for the Extra Chill multisite network. The plugin keeps public-facing link pages on `extrachill.link` while managing artists and subscribers from artist.extrachill.com.

## Key Capabilities

- **Join Flow System**: Entry via `extrachill.link/join` redirects through sunrise.php to `artist.extrachill.com/login/?from_join=true`, renders the join modal (`ec_render_join_flow_modal`) only when the `from_join` flag is present, and routes authenticated users to `/create-artist/` or `/manage-link-page/` via `ec_join_flow_login_redirect`, `ec_join_flow_registration_redirect`, and `ec_get_join_flow_destination`.
- **Gutenberg Block-Based Management**: All artist and link page editing happens inside tailored React blocks (`src/blocks/*/`). Blocks communicate via a shared REST API client (`src/blocks/shared/api/client.js`) and live preview contexts.
- **Link Pages on extrachill.link**: Live pages render through `inc/link-pages/live/templates/extrch-link-page-template.php`, receive data from `ec_get_link_page_data()`, and include analytics, subscription modals, share tools, and a permission-aware edit button.
- **Analytics & Subscriptions**: Daily view and click aggregation land in `{prefix}_extrch_link_page_daily_views`/`daily_link_clicks`, while `{prefix}_artist_subscribers` stores email subscribers submitted through inline forms or modal flows handled by `inc/link-pages/live/assets/js/link-page-subscribe.js`.
- **Roster & Permissions**: Roster invitations, member management, and permission checks ride on `inc/artist-profiles/roster/` data functions and the centralized permission filters in `inc/core/filters/permissions.php` (`ec_can_manage_artist()`, `ec_can_manage_link_page()`).

## Gutenberg Blocks

- **Link Page Editor** (`src/blocks/link-page-editor/`): Tabbed interface (Info, Links, Customize, Advanced, Socials) with dnd-kit reordering, live preview via PreviewContext, CSS custom properties, QR code generation, and advanced settings for tracking, redirects, and expiration.
- **Link Page Analytics** (`src/blocks/artist-analytics/`): Chart.js dashboard, artist switcher, date filtering, and REST-driven data powered by `inc/database/link-page-analytics-db.php` and `inc/link-pages/live/analytics.php` handlers.
- **Artist Manager** (`src/blocks/artist-manager/`): Profile info, socials, roster members, and subscriber exports with REST API hooks for member invitations and removals.
- **Artist Creator** (`src/blocks/artist-creator/`): Guided profile creation that spins up an `artist_profile` post and companion link page for new artists when routed from the join flow.
- **Artist Shop Manager** (`src/blocks/artist-shop-manager/`): Full shop CRUD interface with product management, image uploads, inventory tracking, order fulfillment, shipping label purchasing, and Stripe Connect payment integration.

## Architecture & Data

- **Plugin Core**: `ExtraChillArtistPlatform` bootstraps the plugin, `ExtraChillArtistPlatform_PageTemplates` manages template routing, and `ExtraChillArtistPlatform_Assets` enqueues CSS/JS based on context (`inc/core/artist-platform-assets.php`).
- **Data Systems**: `ec_get_link_page_data()` and `ec_get_artist_profile_data()` deliver unified data structures (links, CSS vars, socials, advanced settings). Filters like `extrch_get_link_page_data` and `extrch_get_link_page_analytics` let other plugins inject or read structured content.
- **Templates**: Public templates live under `inc/link-pages/live/templates/`, while artist templates and reusable components live under `inc/artist-profiles/frontend/templates/`, `inc/home/templates/`, and `inc/core/templates/`. Component rendering relies on `ec_render_template()` for modular sections.
- **REST API & Permissions**: Gutenberg blocks call `/wp-json/extrachill/v1/*` endpoints with nonces, while server-side permission helpers (`ec_can_manage_artist()`, `ec_can_create_artist_profiles()`) gate access across UI and API surfaces.

## Requirements

- WordPress 5.0+ (tested through 6.4)
- PHP 7.4+
- Network-activated `extrachill-users` plugin (authentication, cross-domain cookies)
- Extrachill theme on artist.extrachill.com for template overrides
- Optional `extrachill-multisite` for extended network utilities

## Build & Development

- Gutenberg blocks compile through `npm run build` (production) or `npm run start` (development watch) using `webpack.config.js` and `@wordpress/scripts`.
- Production packaging runs via `./build.sh`, which symlinks to the shared `../../.github/build.sh` pipeline and produces `/build/extrachill-artist-platform.zip` while honoring `.buildignore` filters.
- PHP assets and database helpers are organized under `inc/` (core, artist-profiles, link-pages, join, home, database) and rely on WordPress hooks/filters for extensibility.

## Support

Report issues or feature requests in this repository so the Extra Chill engineering team can respond with updates or clarifications.