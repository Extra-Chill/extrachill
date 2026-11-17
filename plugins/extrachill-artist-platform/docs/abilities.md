# Artist Platform abilities

The plugin’s domain abilities cover artist profiles, Link Pages, socials, subscribers, local support and relationship administration in the `extrachill-artist-platform`, `extrachill-artists`, and `extrachill-admin-artist-relationships` categories.

Registrations and schemas live in [registry.php](../inc/abilities/registry.php); execution callbacks live in [handlers](../inc/abilities/handlers/). Inputs below are objects, with required/optional status taken from `input_schema`. Successful outputs are described; callbacks may also return `WP_Error`. REST routes below use the `/wp-json/extrachill/v1` prefix. `show_in_rest` refers to core ability exposure, not these separate API shims.

Shared [actor helpers](../inc/abilities/helpers.php) resolve an Agents API principal before the ambient session and enforce its capability ceiling when present. A userless system principal is trusted only in CLI/cron contexts; without a principal, direct WP-CLI and executing Action Scheduler jobs are trusted. Resolution exceptions deny access. CLI commands below are registered under `wp extrachill artists` and require the Artist Platform site context.

## Profiles and discovery

### `extrachill/artists-list`

- **Purpose:** Returns paginated list of published artist profiles.
- **Input:** `page` (integer, optional) [minimum=1]; `per_page` (integer, optional) [minimum=1; maximum=100]; `search` (string, optional); `local_scene` (string, optional). Additional properties are rejected.
- **Output:** object {`artists` (optional): array of any; `total` (optional): integer; `page` (optional): integer; `per_page` (optional): integer; `pages` (optional): integer}.
- **Permission:** `__return_true` (public; no authentication check).
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: `wp extrachill artists count`.

### `extrachill/artist-get`

- **Purpose:** Returns the canonical published artist profile payload, including imagery, profile details, and official links. See [existing documentation](artist-profiles/read-model.md).
- **Input:** `id` (integer, required) [minimum=1]. Additional properties are rejected.
- **Output:** object {`id` (optional): integer; `name` (optional): string; `slug` (optional): string; `permalink` (optional): string [uri]; `bio` (optional): string; `local_scene` (optional): string | null; `local_city` (optional): string | null; `genres` (optional): array of string; `genre_labels` (optional): array of string; `profile_image_id` (optional): integer | null; `profile_image_url` (optional): string | null; `header_image_id` (optional): integer | null; `header_image_url` (optional): string | null; `official_links` (optional): array of any; `link_page_id` (optional): integer | null}.
- **Permission:** `__return_true` (public; no authentication check).
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.

### `extrachill/get-artist-data`

- **Purpose:** Retrieve core artist profile data including name, bio, images, and link page ID.
- **Input:** `artist_id` (integer, required)
- **Output:** Object with `id`, `name`, `slug`, `bio`, nullable `local_city`/`local_scene`, `local_scene_label`, `genres`/`genre_labels` arrays, nullable profile/header image IDs and URLs, and nullable `link_page_id`.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: `GET /artists/{id}`. CLI command: `wp extrachill artists get`.

### `extrachill/create-artist`

- **Purpose:** Create a new artist profile and link the current user as a member.
- **Input:** `name` (string, required); `bio` (string, optional); `local_scene` (string, optional); `local_city` (string, optional); `genres` (array of string, optional) [maxItems=3]; `user_id` (integer, optional)
- **Output:** Object with `id`, `name`, `slug`, `bio`, nullable `local_city`/`local_scene`, `local_scene_label`, `genres`/`genre_labels` arrays, nullable profile/header image IDs and URLs, and nullable `link_page_id`. Creation overrides `link_page_id` to `0` (no automatic page).
- **Permission:** Resolved actor needs `create_artist_profile` for self-creation via `ec_user_can`. Creating for another user requires `manage_options` or `manage_network_options`. Trusted system execution requires a positive target user ID.
- **Exposure:** `show_in_rest: true`. API route: `POST /artists`. CLI command: `wp extrachill artists create`.
- **Side effects:** Publishes an artist profile, establishes reciprocal membership, stores scene/city and genres, synchronizes canonical term binding, and emits the profile-created funnel event when enabled. Failures attempt membership/profile rollback. Does not provision a Link Page.

### `extrachill/update-artist`

- **Purpose:** Update an existing artist profile. Supports partial updates. See [existing documentation](profile-image-storage.md).
- **Input:** `artist_id` (integer, required); `name` (string, optional); `bio` (string, optional); `local_scene` (string, optional); `local_city` (string, optional); `genres` (array of string, optional) [maxItems=3]; `profile_image_id` (integer, optional); `header_image_id` (integer, optional)
- **Output:** Object with `id`, `name`, `slug`, `bio`, nullable `local_city`/`local_scene`, `local_scene_label`, `genres`/`genre_labels` arrays, nullable profile/header image IDs and URLs, and nullable `link_page_id`. If the read ability is absent, returns only `id`.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: `PUT /artists/{id}`. CLI command: `wp extrachill artists update`.
- **Side effects:** Updates supplied post fields, scene/city, genres and image metadata (0 removes images; empty genres clears). Projection-affecting updates lock the Link Page, push artist identity, and fire `ec_link_page_save` when a page exists.

### `extrachill/artist-public-projections`

- **Purpose:** Resolve canonical public artist names and profile URLs from subscription slugs.
- **Input:** `schema_version` (string (1), required); `slugs` (array of string, required) [minItems=1; maxItems=100; uniqueItems=true]. Additional properties are rejected.
- **Output:** object {`schema_version` (required): string (1); `items` (required): array of object {`slug` (required): string; `status` (required): string (resolved, not_found); `name` (required): string; `url` (required): string}}.
- **Permission:** `__return_true` (public; no authentication check).
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.

### `extrachill/artist-get-permissions`

- **Purpose:** Checks current user permissions for an artist profile.
- **Input:** `id` (integer, required) [minimum=1]. Additional properties are rejected.
- **Output:** object {`can_edit` (optional): boolean; `manage_url` (optional): string; `user_id` (optional): integer}.
- **Permission:** `__return_true` (public; no authentication check).
- **Exposure:** `show_in_rest: true`. API route: `GET /artists/{id}/permissions`. CLI command: none found.

### `extrachill/artist-get-roster`

- **Purpose:** Lists linked members and pending invites for an artist.
- **Input:** `id` (integer, required) [minimum=1]. Additional properties are rejected.
- **Output:** object {`members` (optional): array of any; `invites` (optional): array of any}.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: `GET /artists/{id}/roster`. CLI command: none found.

### `extrachill/get-artist-platform-stats`

- **Purpose:** Point-in-time platform aggregates: total published artist profiles, total published link pages, profiles created in last N days, and link pages with at least one view/click in last N days. Funnel conversion-over-time is read separately via extrachill/get-analytics-summary.
- **Input:** `days` (integer, optional) [minimum=0; maximum=90]. Additional properties are rejected.
- **Output:** object {`total_artist_profiles` (optional): integer; `total_link_pages` (optional): integer; `profiles_created_recent` (optional): integer; `active_link_pages_recent` (optional): integer | null; `link_page_analytics_status` (optional): string (available, no_data, disabled, unavailable, error, malformed_response); `link_page_analytics_error` (optional): string | null; `days` (optional): integer}.
- **Permission:** Resolved actor has `manage_options`, or is trusted system execution; delegated capability ceilings apply.
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: `wp extrachill artists stats`.

## Link Pages

### `extrachill/get-link-page-data`

- **Purpose:** Retrieve complete link page data including links, styles, settings, and socials.
- **Input:** `artist_id` (integer, required); `link_page_id` (integer, optional)
- **Output:** Object returned by the Link Pages runtime: complete page data including `link_page_id`, `links`, `css_vars`, `settings`, and social data; registration intentionally leaves the object fields unconstrained.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: `GET /artists/{id}/links; PUT /artists/{id}/links (final read); POST /media (Link Page image read)`. CLI command: `wp extrachill artists link-page`, `add-link`, `remove-link`, `move-link`, `save-styles --list`, `save-settings --list`.

### `extrachill/artist-get-links`

- **Purpose:** Retrieves complete link page data for an artist.
- **Input:** `id` (integer, required) [minimum=1]. Additional properties are rejected.
- **Output:** Object returned by the Link Pages runtime: complete page data including `link_page_id`, `links`, `css_vars`, `settings`, and social data; registration intentionally leaves the object fields unconstrained.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.

### `extrachill/create-artist-link-page`

- **Purpose:** Create the Link Page for an artist that does not have one yet. Returns the existing page when one already exists.
- **Input:** `artist_id` (integer, required)
- **Output:** object.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.
- **Side effects:** Creates the owned page if absent and publishes it; also publishes an existing unpublished page.

### `extrachill/save-link-page-links`

- **Purpose:** Save link sections and buttons to a link page. Full replacement with ID assignment. Links support a featured boolean (first on the page wins) and optional thumbnail_url (absolute HTTP/HTTPS only).
- **Input:** `artist_id` (integer, required); `links` (array of any, required); `allow_empty` (boolean, optional)
- **Output:** Object returned by the Link Pages runtime: complete page data including `link_page_id`, `links`, `css_vars`, `settings`, and social data; registration intentionally leaves the object fields unconstrained.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: `PUT /artists/{id}/links`. CLI command: `wp extrachill artists save-links`, `add-link`, `remove-link`, `move-link`.
- **Side effects:** Replaces stored sections/buttons through `ec_save_link_page`, with sanitization and persistent ID assignment. Empty replacement of a populated page requires strictly `allow_empty=true`. Runs the shared save/cache invalidation path.

### `extrachill/save-link-page-styles`

- **Purpose:** Sanitizes and merges CSS variables into the existing Link Page styles, including button, gradient, card and background-image treatments.
- **Input:** `artist_id` (integer, required); `css_vars` (object, required)
- **Output:** Object returned by the Link Pages runtime: complete page data including `link_page_id`, `links`, `css_vars`, `settings`, and social data; registration intentionally leaves the object fields unconstrained.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: `PUT /artists/{id}/links`. CLI command: `wp extrachill artists save-styles`.
- **Side effects:** Sanitizes and merges the CSS variable patch through `ec_save_link_page` under the canonical storage lock; runs the shared save/cache invalidation path.

### `extrachill/save-link-page-settings`

- **Purpose:** Save advanced settings for a link page. Merges with existing settings. See [existing documentation](profile-image-storage.md).
- **Input:** `artist_id` (integer, required); `settings` (object, optional); `bio` (string, optional); `background_image_id` (integer, optional); `profile_image_id` (integer, optional)
- **Output:** Object returned by the Link Pages runtime: complete page data including `link_page_id`, `links`, `css_vars`, `settings`, and social data; registration intentionally leaves the object fields unconstrained.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: `PUT /artists/{id}/links; POST /media (Link Page images)`. CLI command: `wp extrachill artists save-settings`.
- **Side effects:** Saves only supplied sanitized settings/bio/images through `ec_save_link_page`; profile images supplied from Link Page storage are copied to the artist site. Runs the shared save/cache invalidation path.

### `extrachill/artist-update-links`

- **Purpose:** Updates link page data (links, styles, settings, socials). See [existing documentation](link-pages/link-page-system.md).
- **Input:** `id` (integer, required) [minimum=1]; `links` (array of any, optional); `css_vars` (object, optional); `settings` (object, optional); `socials` (array of any, optional); `background_image_id` (integer, optional); `profile_image_id` (integer, optional); `bio` (string, optional). Additional properties are rejected.
- **Output:** Object returned by the Link Pages runtime: complete page data including `link_page_id`, `links`, `css_vars`, `settings`, and social data; registration intentionally leaves the object fields unconstrained.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.
- **Side effects:** Sequentially invokes the supplied links, styles, settings and socials save abilities; changes made before an error are not rolled back. Missing save abilities are skipped. The input does not expose `allow_empty`.

### `extrachill/preview-link-page`

- **Purpose:** Preview an artist Link Page: public URL, resolved CSS defaults and derived variables, and WCAG contrast checks (4.5 threshold). Also captures a full-page screenshot of the live public URL at the viewport (default 390x844) and returns its image URL; pass screenshot=false to skip it. See [existing documentation](preview-link-page.md).
- **Input:** `artist_id` (integer, required) [minimum=1]; `screenshot` (boolean, optional) [default=true]; `viewport` (object {`width` (required): integer; `height` (required): integer}, optional) [default={'width': 390, 'height': 844}]. Additional properties are rejected.
- **Output:** object {`public_url` (required): string [uri]; `viewport` (required): object; `resolved_styles` (required): object; `contrast_report` (required): object; `screenshot` (required): object}.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.
- **Side effects:** Despite the readonly annotation, may cache background-image color calculations and launch a screenshot runner/headless browser against the live public URL, writing an image in uploads. `screenshot=false` skips capture.

## Social links

### `extrachill/artist-list-socials`

- **Purpose:** Lists social links for an artist profile.
- **Input:** `id` (integer, required) [minimum=1]. Additional properties are rejected.
- **Output:** object {`social_links` (optional): array of any}.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.

### `extrachill/artist-create-social`

- **Purpose:** Adds a social link to an artist profile.
- **Input:** `id` (integer, required) [minimum=1]; `type` (string, required); `url` (string [uri], required). Additional properties are rejected.
- **Output:** object {`social_links` (optional): array of any}.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.
- **Side effects:** Appends and persists a sanitized social link under the Link Page lock, assigning IDs and firing `ec_link_page_save` when a page exists. Attempts restoration on save failure.

### `extrachill/artist-update-social`

- **Purpose:** Updates a single social link on an artist profile.
- **Input:** `id` (integer, required) [minimum=1]; `social_id` (string, required); `type` (string, optional); `url` (string [uri], optional). Additional properties are rejected.
- **Output:** object {`social_links` (optional): array of any}.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.
- **Side effects:** Persists the patched social list under the Link Page lock, synchronizes IDs, and fires `ec_link_page_save` when a page exists. Attempts restoration on save failure.

### `extrachill/artist-delete-social`

- **Purpose:** Removes a social link from an artist profile.
- **Input:** `id` (integer, required) [minimum=1]; `social_id` (string, required). Additional properties are rejected.
- **Output:** object {`deleted` (optional): boolean; `social_id` (optional): string; `social_links` (optional): array of any}.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.
- **Side effects:** Removes the matching social ID under the Link Page lock and fires `ec_link_page_save` when a page exists. Missing IDs return `not_found`; save failures attempt restoration.

### `extrachill/save-social-links`

- **Purpose:** Save social links for an artist profile. Full replacement.
- **Input:** `artist_id` (integer, required); `social_links` (array of any, required)
- **Output:** object.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: `PUT /artists/{id}/socials; PUT /artists/{id}/links`. CLI command: `wp extrachill artists save-socials`.
- **Side effects:** Fully replaces artist social links under the Link Page lock, assigns/synchronizes social IDs, and fires `ec_link_page_save`; attempts restoration on persistence failure.

## Subscribers and analytics

### `extrachill/artist-subscribe`

- **Purpose:** Subscribes an email address to an artist for updates.
- **Input:** `id` (integer, required) [minimum=1]; `email` (string [email], required). Additional properties are rejected.
- **Output:** object {`message` (optional): string}.
- **Permission:** `__return_true` (public; no authentication check).
- **Exposure:** `show_in_rest: true`. API route: `POST /artists/{id}/subscribe`. CLI command: none found.
- **Side effects:** Inserts into the current blog’s `artist_subscribers` table with UTC subscription time and `exported=0`; duplicate subscriptions return a 409 error. Does not send email.

### `extrachill/artist-list-subscribers`

- **Purpose:** Returns paginated subscriber list for an artist.
- **Input:** `id` (integer, required) [minimum=1]; `page` (integer, optional) [minimum=1]; `per_page` (integer, optional) [minimum=1; maximum=100]. Additional properties are rejected.
- **Output:** object {`subscribers` (optional): array of any; `total` (optional): integer; `per_page` (optional): integer; `page` (optional): integer}.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: `GET /artists/{id}/subscribers`. CLI command: none found.

### `extrachill/artist-export-subscribers`

- **Purpose:** Returns all subscribers for client-side CSV generation.
- **Input:** `id` (integer, required) [minimum=1]; `include_exported` (boolean, optional). Additional properties are rejected.
- **Output:** object {`subscribers` (optional): array of any; `artist_name` (optional): string; `export_date` (optional): string; `total` (optional): integer; `marked_count` (optional): integer}.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: `GET /artists/{id}/subscribers/export`. CLI command: none found.
- **Side effects:** Unless `include_exported=true`, marks newly exported subscriber rows `exported=1`. Returns data for client-side CSV creation, not a file.

### `extrachill/artist-subscriber-unsubscribe`

- **Purpose:** Resolves a signed artist subscriber unsubscribe link (as included in subscriber exports). Without `confirm` it returns a scanner-safe confirmation page and changes nothing; with `confirm` it removes that email from the artist’s list.
- **Input:** `token` (string, optional) — signed unsubscribe token; `confirm` (boolean, optional) — perform the unsubscribe (POST) instead of rendering the confirmation (GET). Additional properties are rejected.
- **Output:** object {`status` (required): string [enum: `confirm`, `unsubscribed`, `invalid`, `unavailable`]; `title` (required): string; `message` (required): string; `html` (required): string (standalone HTML document)}. Invalid or missing tokens return `status: invalid` rather than an error. A valid token whose subscription is already gone returns `unsubscribed`.
- **Permission:** `__return_true` (public). The signed token is the authorization; it never expires and only covers removing that email from that one artist’s list.
- **Exposure:** `show_in_rest: false`. API route: `GET /artists/subscribers/unsubscribe` (confirmation page) and `POST /artists/subscribers/unsubscribe` (confirm), served as `text/html`. CLI command: none found.
- **Side effects:** With `confirm=true`, deletes the matching row from the current blog’s `artist_subscribers` table (matched by the token’s email fingerprint, first on the hinted row ID, then across all of that artist’s rows in case the person resubscribed). Without `confirm`, read-only.

### `extrachill/list-my-artist-subscriptions`

- **Purpose:** Lists the artist mailing lists the current member is on, matched by account or account email.
- **Input:** none (empty object). Additional properties are rejected.
- **Output:** object {`subscriptions` (required): array of object {`subscriber_id`: integer; `artist_id`: integer; `artist_name`: string; `artist_url`: string; `subscribed_at`: string}}. Rows match the current blog’s `artist_subscribers` table by `user_id` or by the account’s `user_email` (so signed-out or pre-account signups appear), newest `subscribed_at` first. `artist_url` is empty when the artist profile no longer exists. A failed database read returns a 500 `artist_subscriptions_read_failed` error.
- **Permission:** `is_user_logged_in`. Always scoped to the current user; no artist-management capability is required.
- **Exposure:** `show_in_rest: true`. API route: `GET /artists/me/subscriptions`. CLI command: none found.

### `extrachill/remove-my-artist-subscription`

- **Purpose:** Removes the current member from one artist mailing list they are on.
- **Input:** `subscriber_id` (integer, required) [minimum=1] — a subscription ID from `extrachill/list-my-artist-subscriptions`. Additional properties are rejected.
- **Output:** object {`removed` (optional): boolean; `subscriber_id` (optional): integer}. Returns `removed: true` with the ID on success. A row that does not belong to the current user (by `user_id` or account email) returns a 404 `artist_subscription_not_found` error, so other people’s subscriptions are never revealed; a failed delete returns a 500 `artist_unsubscribe_failed` error.
- **Permission:** `is_user_logged_in`. Ownership is enforced in the execute callback, not the permission callback.
- **Exposure:** `show_in_rest: true`. API route: `DELETE /artists/me/subscriptions/{subscriber_id}`. CLI command: none found.
- **Side effects:** Deletes the matching row from the current blog’s `artist_subscribers` table. Does not send email.

### `extrachill/artist-get-analytics`

- **Purpose:** Returns link page analytics for an artist.
- **Input:** `id` (integer, required) [minimum=1]; `date_range` (integer, optional) [minimum=1; maximum=90]; `start_date` (string, optional) [pattern=^\d{4}-\d{2}-\d{2}$]; `end_date` (string, optional) [pattern=^\d{4}-\d{2}-\d{2}$]. Additional properties are rejected.
- **Output:** object {`summary` (optional): object; `chart_data` (optional): object; `top_links` (optional): array of any}.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: `GET /artists/{id}/analytics`. CLI command: none found.

## Local scenes and support

### `extrachill/artist-backfill-local-scenes`

- **Purpose:** Maps legacy free-text artist locations to canonical Local Scenes. Dry run unless apply is true; existing scenes are never overwritten.
- **Input:** `apply` (boolean, optional) [default=false]. Additional properties are rejected.
- **Output:** Array of objects with `artist_id`, `local_city`, `local_scene`, `status` for considered profiles without an existing scene.
- **Permission:** Resolved actor must have `manage_network_options`, or be trusted system execution; delegated capability ceilings apply.
- **Exposure:** `show_in_rest: false`. API route: none found. CLI command: `wp extrachill artists backfill-local-scenes`.
- **Side effects:** Dry run by default; `apply=true` stores matched canonical scene slugs. Never overwrites an existing scene.

### `extrachill/artist-get-local-support-availability`

- **Purpose:** Returns local support availability for an exactly managed artist.
- **Input:** `id` (integer, required) [minimum=1]. Additional properties are rejected.
- **Output:** object {`artist_id` (required): integer; `available` (required): boolean; `scene` (required): object | null}.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above.
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.

### `extrachill/artist-update-local-support-availability`

- **Purpose:** Enables or disables local support matching for an exactly managed artist.
- **Input:** `id` (integer, required) [minimum=1]; `available` (boolean, required); `scene_slug` (string, optional). Additional properties are rejected.
- **Output:** object {`artist_id` (required): integer; `available` (required): boolean; `scene` (required): object | null}.
- **Permission:** Artist-management check: nonzero `artist_id`/`id` and `ec_user_can('manage_artist', {artist_id, user_id: acting user})`. Trusted system execution accepts a nonzero artist ID. See the actor rules above. The mutation also requires a positive acting user and `ec_can_manage_artist(actor_id, artist_id)`; a userless trusted system actor alone is insufficient.
- **Exposure:** `show_in_rest: true`. API route: none found. CLI command: none found.
- **Side effects:** Enabling stores the resolved hometown scene and `_local_support_available=1`; scene preference is explicit override, existing artist scene, then acting user scene. Disabling deletes only the availability flag and preserves hometown.

### `extrachill/artist-query-local-support-candidates`

- **Purpose:** Returns privacy-safe local support candidates for an authorized producer.
- **Input:** `producer` (string, required); `scene_slug` (string, required); `genres` (array of string, optional); `exclude_artist_ids` (array of integer, optional). Additional properties are rejected.
- **Output:** object {`location` (required): object; `candidates` (required): array of object {`artist_profile_id` (required): integer; `artist_term_id` (required): integer; `name` (required): string; `slug` (required): string; `permalink` (required): string [uri]; `genres` (required): array of string; `genre_labels` (required): array of string; `local_city` (required): string | null; `profile_image_url` (required): string | null; `header_image_url` (required): string | null; `manager_user_ids` (required): array of integer}}.
- **Permission:** Registration allows the admin check or a nonempty producer, valid scene, and `extrachill_artist_platform_local_support_producer_authorized` filter approval. The execute path always requires that producer filter approval, even for admins.
- **Exposure:** `show_in_rest: false`. API route: none found. CLI command: none found.

## External onboarding

### `extrachill/onboard-external-artist`

- **Purpose:** Resolve or provision a consent-aware artist onboarding offer from a generic external source.
- **Input:** `submitter_user_id` (integer, optional) [minimum=1]; `submitter_email` (string [email], optional); `artist_name` (string, required) [minLength=1]; `artist_term_id` (integer, optional) [minimum=1]; `artist_profile_id` (integer, optional) [minimum=1]; `source_type` (string, required) [minLength=1]; `source_id` (string, required) [minLength=1]; `return_url` (string [uri], optional); `consent` (object {`profile_creation` (optional): boolean; `link_page` (optional): boolean; `disclosure_version` (optional): string}, optional). Additional properties are rejected, including inside `consent`. The callback additionally requires a submitter user ID or email, and a disclosure version when consent is asserted.
- **Output:** object {`outcome` (required): string (account_claim_required, authentication_required, artist_consent_required, membership_request_required, managed_artist, artist_created); `user` (required): object {`id` (required): integer; `state` (required): string (unclaimed, created, existing); `created` (required): boolean}; `artist` (required): object {`name` (required): string; `profile_id` (required): integer | null; `term_id` (required): integer | null; `state` (required): string (existing_profile, existing_canonical_identity, new_eligible, eligible_after_claim_and_consent, eligible_after_authentication_and_consent, eligible_after_consent, created); `disclosure_version` (optional): string}; `membership` (required): object {`state` (required): string (managed, request_required, not_applicable)}; `claim` (required): object {`required` (required): boolean; `delivery` (required): string (not_required, previously_provisioned, sent, previously_sent, pending, busy, failed, sent_unconfirmed)}; `link_page` (required): object {`state` (required): string (unavailable, offered, existing, created); `id` (required): integer | null}; `source` (required): object {`type` (required): string; `id` (required): string}; `return_url` (required): string; `next_action` (required): string (none, claim_account, request_membership, authenticate, confirm_profile_creation, manage_artist)}.
- **Permission:** Direct WP-CLI, `manage_options`, `manage_network_options`, or Action Scheduler execution; otherwise the `extrachill_allow_external_artist_onboarding` filter must explicitly allow the input. This callback uses ambient capabilities rather than the shared actor helper.
- **Exposure:** `show_in_rest: false`. API route: none found. CLI command: none found.
- **Side effects:** May provision a claimable account and send account-claim email through Users contracts, persist claim-delivery state, create a consented artist profile/membership and canonical binding, record source provenance, and optionally create a consented Link Page. Uses advisory locks and attempts rollback on provisioning failures.

## Relationship administration

### `extrachill/admin-list-artist-relationships`

- **Purpose:** Lists artist-user relationships by artists or users view. See [existing documentation](../../extrachill-api/docs/routes/admin/artist-relationships.md).
- **Input:** `view` (string (artists, users), optional); `search` (string, optional). Additional properties are rejected.
- **Output:** object {`items` (optional): array of any}.
- **Permission:** Resolved actor must have `manage_network_options`, or be trusted system execution; delegated capability ceilings apply.
- **Exposure:** `show_in_rest: true`. API route: `GET /admin/artist-relationships`. CLI command: none found.

### `extrachill/admin-link-artist-relationship`

- **Purpose:** Links a user to an artist profile. See [existing documentation](../../extrachill-api/docs/routes/admin/artist-relationships.md).
- **Input:** `user_id` (integer, required) [minimum=1]; `artist_id` (integer, required) [minimum=1]. Additional properties are rejected.
- **Output:** object {`success` (optional): boolean}.
- **Permission:** Resolved actor must have `manage_network_options`, or be trusted system execution; delegated capability ceilings apply.
- **Exposure:** `show_in_rest: true`. API route: `POST /admin/artist-relationships/link`. CLI command: none found.
- **Side effects:** Adds reciprocal artist membership via the Users membership contract, including its cache reconciliation. Returns an error when the relationship cannot be fully persisted.

### `extrachill/admin-unlink-artist-relationship`

- **Purpose:** Unlinks a user from an artist profile. See [existing documentation](../../extrachill-api/docs/routes/admin/artist-relationships.md).
- **Input:** `user_id` (integer, required) [minimum=1]; `artist_id` (integer, required) [minimum=1]. Additional properties are rejected.
- **Output:** object {`success` (optional): boolean}.
- **Permission:** Resolved actor must have `manage_network_options`, or be trusted system execution; delegated capability ceilings apply.
- **Exposure:** `show_in_rest: true`. API route: `POST /admin/artist-relationships/unlink`. CLI command: none found.
- **Side effects:** Removes reciprocal artist membership via the Users membership contract, including its cache reconciliation. Returns an error when the relationship cannot be fully persisted.

### `extrachill/admin-list-orphan-artist-relationships`

- **Purpose:** Lists orphaned artist-user relationships where the artist post no longer exists. See [existing documentation](../../extrachill-api/docs/routes/admin/artist-relationships.md).
- **Input:** No fields (empty object).. Additional properties are rejected.
- **Output:** object {`orphans` (optional): array of any}.
- **Permission:** Resolved actor must have `manage_network_options`, or be trusted system execution; delegated capability ceilings apply.
- **Exposure:** `show_in_rest: true`. API route: `GET /admin/artist-relationships/orphans`. CLI command: none found.

### `extrachill/admin-cleanup-artist-relationships`

- **Purpose:** Removes an orphaned artist-user relationship entry. See [existing documentation](../../extrachill-api/docs/routes/admin/artist-relationships.md).
- **Input:** `user_id` (integer, required) [minimum=1]; `artist_id` (integer, required) [minimum=1]. Additional properties are rejected.
- **Output:** object {`success` (optional): boolean}.
- **Permission:** Resolved actor must have `manage_network_options`, or be trusted system execution; delegated capability ceilings apply.
- **Exposure:** `show_in_rest: true`. API route: `POST /admin/artist-relationships/cleanup`. CLI command: none found.
- **Side effects:** Removes reciprocal artist membership via the Users membership contract, including its cache reconciliation. Returns an error when the relationship cannot be fully persisted.
