# Users abilities

The plugin’s `extrachill-users` category covers account lifecycle, onboarding, moderation, artist access, profiles, discovery, subscriptions, concert tracking/imports and notifications. These are domain operations consumed by transports; `show_in_rest` controls core Abilities REST exposure, not whether a separate Extra Chill API adapter exists.

This reference follows the current registrations in `inc/core/abilities/register.php`. Input fields below are required only when explicitly marked **required**; all others are optional. Outputs describe successful values; callbacks may also return `WP_Error`. REST paths are relative to `/wp-json`; CLI commands still obey ability permissions. Transport source files are listed where route groups share a file.

The issue’s literal `extrachill/` is a dynamic-registration scanner artifact, not an executable ability: the three real names are `entity-subscribe`, `entity-unsubscribe` and `entity-subscription-status`. Retired `get-subscriptions`/`update-subscriptions` email-sharing abilities are not registered and are not replacements for entity subscriptions.

## Account lifecycle and onboarding

### `extrachill/create-user`

- **Purpose:** Create a community-site account from the supplied username, password and email (the callback does not generate the username itself).
- **Input:** `email` (string, **required**); `password` (string, **required**); `username` (string, **required**); `from_join` (boolean, optional); `registration_page` (string, optional); `registration_source` (string, optional); `registration_method` (string, optional); `role` (string, optional); `unclaimed` (boolean, optional); `referrer` (string, optional); `utm` (object, optional).
- **Output:** `integer`.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy.
- **Exposure:** `show_in_rest: false`; none found.
- **Side effects:** Creates a community-site user and optional role/unclaimed state; writes attribution, onboarding and welcome-email metadata; fires registration hooks and optionally records analytics. Failed unclaimed-state persistence attempts account rollback.

### `extrachill/resolve-account-by-email`

- **Purpose:** Reuse the account for an email or create an unclaimed subscriber account, and return a throttled claim (set-password) URL when the account is unclaimed. See [existing reference](authentication.md).
- **Input:** `email` (string, **required**); `registration_source` (string, **required**); `registration_page` (string, optional); `referrer` (string, optional); `utm` (object, optional).
- **Output:** `object {user_id: integer, created: boolean, unclaimed: boolean, claim_url: string, claim_throttled: boolean}`.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy.
- **Exposure:** `show_in_rest: false`; none found.
- **Side effects:** May call `create-user` for an unclaimed subscriber with a generated password; may generate a claim/reset-password URL and update throttling state. Existing claimed accounts are reused.

### `extrachill/complete-onboarding`

- **Purpose:** Finalize user onboarding: validate and set username, set artist/professional flags, mark complete, and send welcome email. See [existing reference](onboarding.md).
- **Input:** `user_id` (integer, **required**); `username` (string, **required**); `user_is_artist` (boolean, optional); `user_is_professional` (boolean, optional); `join_intent` (string, optional); `local_scene` (string, optional); `local_scene_visibility` (string; enum ["public", "private"]; default "public", optional).
- **Output:** `object {success: boolean, user: object, redirect_url: string}`.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy.
- **Exposure:** `show_in_rest: false`; REST `POST /extrachill/v1/users/onboarding`.
- **Side effects:** Updates user login/nicename/display name and scene/access/completion metadata under a lock; reconciles access-grant delivery, clears user cache, refreshes current-user/auth cookies, emits hooks/analytics and attempts a welcome email.

### `extrachill/get-onboarding-status`

- **Purpose:** Get onboarding completion state, flags, and current username for a user. See [existing reference](onboarding.md).
- **Input:** `user_id` (integer, **required**).
- **Output:** `object {completed: boolean, from_join: boolean, fields: object}`.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy.
- **Exposure:** `show_in_rest: false`; REST `GET /extrachill/v1/users/onboarding`.

### `extrachill/validate-username`

- **Purpose:** Check if a username is valid (length, characters, reserved words) and available. See [existing reference](onboarding.md).
- **Input:** `username` (string, **required**); `user_id` (integer, optional).
- **Output:** `boolean`.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy.
- **Exposure:** `show_in_rest: false`; none found.

### `extrachill/send-welcome-email`

- **Purpose:** Send welcome email with content based on onboarding status. Skips if already sent.
- **Input:** `user_id` (integer, **required**); `email_type` (string; enum ["onboarding_complete", "onboarding_incomplete"], **required**).
- **Output:** `boolean`.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy.
- **Exposure:** `show_in_rest: false`; none found.
- **Side effects:** Sends the selected welcome email unless already sent; records per-variant and legacy sent flags, plus reminder timestamp/analytics for incomplete onboarding.

### `extrachill/delete-my-account`

- **Purpose:** Permanently delete the authenticated user account across the Extra Chill network. Requires confirm: "DELETE". Staff accounts are refused.
- **Input:** `confirm` (string, **required**).
- **Output:** `object {deleted: boolean}`.
- **Permission:** Requires an authenticated self user and refuses accounts protected from self-deletion; execute repeats the protection check. Super admins, team members and users with administrator/team roles on any site are protected. Execute resolves the authenticated self user, ignoring any supplied user ID.
- **Exposure:** `show_in_rest: true`; none found.
- **Side effects:** Best-effort Apple token revocation, auth-state revocation, newsletter unsubscribe and cleanup hook precede permanent network user deletion. Clears current user/auth cookie after success; destructive and irreversible.

## Moderation

### `extrachill/get-user-moderation-status`

- **Purpose:** Retrieve the current moderation state for a user.
- **Input:** `user_id` (integer, **required**).
- **Output:** `{active, state, user_id}` for active users; blocked records also include `reason_key`, `reason`, `note`, `source`, `acted_at`, `acted_by`, `effects` and `policy`.
- **Permission:** Allows WP-CLI or `manage_network_users`, then applies the `extrachill_users_can_moderate_users` filter.
- **Exposure:** `show_in_rest: false`; CLI `wp extrachill users ban-status`.

### `extrachill/moderate-user`

- **Purpose:** Apply a moderation action such as ban or suspension to a user account. The reason_key is also the suspend-vs-hide-content switch: spam, abuse and fraud additionally hide the user's content (posts to draft, bbPress topics/replies and comments to spam; spam also flags content as spam), while impersonation and other suspend login only and leave all content live. Set purge_content to true to instead PERMANENTLY hard-delete the user's content network-wide (posts, bbPress topics/replies, comments and owned/orphaned attachments) — this is destructive, irreversible, opt-in only, and supersedes the hide behavior.
- **Input:** `user_id` (integer, **required**); `state` (string, **required**); `reason_key` (string, **required**); `reason` (string, optional); `note` (string, optional); `source` (string, optional); `acted_by` (integer, optional); `purge_content` (boolean; default false, optional).
- **Output:** The moderation-status object described above, with optional `results` for applied content actions.
- **Permission:** Allows WP-CLI or `manage_network_users`, then applies the `extrachill_users_can_moderate_users` filter.
- **Exposure:** `show_in_rest: false`; CLI `wp extrachill users ban`.
- **Side effects:** Persists moderation state and applies login restrictions; may revoke Artist Dispatch access and send a policy-driven moderation email. Spam/abuse/fraud also hide content; impersonation/other leave content live. `purge_content: true` irreversibly hard-deletes network content and supersedes hiding.

### `extrachill/clear-user-moderation`

- **Purpose:** Remove moderation state from a user account.
- **Input:** `user_id` (integer, **required**).
- **Output:** `{active: true, state: "active", user_id}`.
- **Permission:** Allows WP-CLI or `manage_network_users`, then applies the `extrachill_users_can_moderate_users` filter.
- **Exposure:** `show_in_rest: false`; CLI `wp extrachill users unban`.
- **Side effects:** Deletes current and legacy moderation metadata; does not restore separately revoked product access.

## Artist access

### `extrachill/list-artist-access-requests`

- **Purpose:** Get all pending artist platform access requests.
- **Input:** No declared fields (object input).
- **Output:** `{requests: [{user_id, user_login, user_email, type, requested_at}]}`.
- **Permission:** Requires `manage_network_options` or WP-CLI; execute also rechecks this gate.
- **Exposure:** `show_in_rest: false`; REST `GET /extrachill/v1/admin/artist-access`; CLI `wp extrachill users access list`.

### `extrachill/approve-artist-access`

- **Purpose:** Approve a pending artist platform access request.
- **Input:** `user_id` (integer, **required**); `type` (string; enum ["artist", "professional"], **required**).
- **Output:** `{success, message, user_id, user_login, type}`; already-granted access returns `{success, message, user_id, skipped: true}`.
- **Permission:** Requires `manage_network_options` or WP-CLI; execute also rechecks this gate.
- **Exposure:** `show_in_rest: false`; REST `POST /extrachill/v1/admin/artist-access/{user_id}/approve`; CLI `wp extrachill users access approve`.
- **Side effects:** Sets artist/professional metadata, deletes the pending request, emits access-approved analytics and sends approval email. Already-granted access is skipped.

### `extrachill/request-artist-access`

- **Purpose:** Submit a request for artist platform access. User-facing ability.
- **Input:** `type` (string; enum ["artist", "professional"], **required**).
- **Output:** `{success, message, user_id, type}`.
- **Permission:** Requires a logged-in user. Execute resolves the authenticated self user, ignoring any supplied user ID.
- **Exposure:** `show_in_rest: true`; REST `/extrachill/v1/users/me/artist-access` (`inc/routes/users/artist-access-request.php`).
- **Side effects:** Writes pending request metadata for the authenticated user, emits analytics and sends an admin notification email with a staged approval link.

### `extrachill/reject-artist-access`

- **Purpose:** Reject a pending artist platform access request.
- **Input:** `user_id` (integer, **required**).
- **Output:** `{success, message, user_id, user_login}`.
- **Permission:** Requires `manage_network_options` or WP-CLI; execute also rechecks this gate.
- **Exposure:** `show_in_rest: false`; REST `POST /extrachill/v1/admin/artist-access/{user_id}/reject`; CLI `wp extrachill users access reject`.
- **Side effects:** Deletes pending request metadata without granting access.

### `extrachill/get-user-artist-access`

- **Purpose:** Returns the current user's artist-access request status (none, pending, or approved).
- **Input:** No declared fields (object input).
- **Output:** `object {user_id: integer, status: string; enum ["none", "pending", "approved"], type: string, request_type: string, requested_at: integer}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; none found.

### `extrachill/get-artist-access-stats`

- **Purpose:** Windowed artist access-request count plus current granted artist/professional counts.
- **Input:** `days` (integer; default 28, optional).
- **Output:** `{days, period, requests_in_window, pending_requests, granted_artist, granted_professional, granted_total}`; requests use the windowed analytics reader, falling back to pending meta when unavailable.
- **Permission:** Requires `manage_options` or WP-CLI.
- **Exposure:** `show_in_rest: false`; CLI `wp extrachill users access stats`.

## Artist Dispatch access

### `extrachill/get-artist-dispatch-access`

- **Purpose:** Get the current user's safe Artist Dispatch eligibility and access state.
- **Input:** No declared fields (object input).
- **Output:** `{status, request_id, requested_at, artist_id, terms_acknowledged, terms_version, terms_renewal_required, decided_at, revoked_at, eligibility}` plus `artist_label` when an artist is selected.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; none found.

### `extrachill/request-artist-dispatch-access`

- **Purpose:** Submit the current user's eligible Artist Dispatch access request.
- **Input:** `artist_id` (integer, optional); `description` (string; minLength 50; maxLength 2000, optional); `sample_url` (string, optional); `acknowledgement` (boolean; enum [true], **required**); `terms_version` (string; enum ["2026-07-18"], **required**).
- **Output:** The same safe state object as `get-artist-dispatch-access` after a successful request.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; none found.
- **Side effects:** Persists an eligible access request through the Artist Dispatch service.

### `extrachill/list-artist-dispatch-access-requests`

- **Purpose:** List Artist Dispatch applications and grants for network administration.
- **Input:** `status` (string; enum ["pending", "approved", "rejected", "revoked"], optional).
- **Output:** `{requests: [{user_id, user_login, display_name, state, artist_label, eligibility, moderation, main_membership}]}`, newest request first.
- **Permission:** Requires `manage_network_options` or WP-CLI; execute also rechecks this gate.
- **Exposure:** `show_in_rest: false`; none found.

### `extrachill/approve-artist-dispatch-access`

- **Purpose:** Approve a matching pending Artist Dispatch request.
- **Input:** `user_id` (integer, **required**); `request_id` (string, **required**); `note` (string; maxLength 2000, optional); `reason` (string; minLength 1; maxLength 2000, optional).
- **Output:** Access transition result object from `ec_users_approve_artist_dispatch_access`.
- **Permission:** Requires `manage_network_options` or WP-CLI; execute also rechecks this gate.
- **Exposure:** `show_in_rest: false`; none found.
- **Side effects:** Approves the matching request through the access service, updating access state and site membership as required.

### `extrachill/reject-artist-dispatch-access`

- **Purpose:** Reject a matching pending Artist Dispatch request.
- **Input:** `user_id` (integer, **required**); `request_id` (string, **required**); `note` (string; maxLength 2000, optional); `reason` (string; minLength 1; maxLength 2000, **required**).
- **Output:** Access transition result object from `ec_users_reject_artist_dispatch_access`.
- **Permission:** Requires `manage_network_options` or WP-CLI; execute also rechecks this gate.
- **Exposure:** `show_in_rest: false`; none found.
- **Side effects:** Records rejection of the matching pending request.

### `extrachill/revoke-artist-dispatch-access`

- **Purpose:** Revoke an approved Artist Dispatch grant.
- **Input:** `user_id` (integer, **required**); `request_id` (string, **required**); `note` (string; maxLength 2000, optional); `reason` (string; minLength 1; maxLength 2000, **required**).
- **Output:** Access transition result object from `ec_users_revoke_artist_dispatch_access`.
- **Permission:** Requires `manage_network_options` or WP-CLI; execute also rechecks this gate.
- **Exposure:** `show_in_rest: false`; none found.
- **Side effects:** Revokes the matching approved access grant through the access service.

## Membership and team administration

### `extrachill/grant-lifetime-membership`

- **Purpose:** Grant lifetime ad-free membership to a user by username or email.
- **Input:** `user_identifier` (string, **required**).
- **Output:** `{success, message, user_id, user_login, user_email}`.
- **Permission:** Allows WP-CLI, or ActionScheduler present after `action_scheduler_before_execute` has fired, or `manage_network_options`.
- **Exposure:** `show_in_rest: true`; REST `POST /extrachill/v1/admin/lifetime-membership/grant`.
- **Side effects:** Creates lifetime membership when absent.

### `extrachill/revoke-lifetime-membership`

- **Purpose:** Revoke lifetime membership from a user.
- **Input:** `user_id` (integer, **required**).
- **Output:** `{success, message, user_id, user_login}`.
- **Permission:** Allows WP-CLI, or ActionScheduler present after `action_scheduler_before_execute` has fired, or `manage_network_options`.
- **Exposure:** `show_in_rest: true`; REST `DELETE /extrachill/v1/admin/lifetime-membership/{user_id}`.
- **Side effects:** Deletes lifetime membership metadata.

### `extrachill/sync-team-members`

- **Purpose:** Sync team member status for all network users based on current role assignments.
- **Input:** No declared fields (object input).
- **Output:** `{total_team_users, sites_processed}`.
- **Permission:** Allows WP-CLI, or ActionScheduler present after `action_scheduler_before_execute` has fired, or `manage_network_options`.
- **Exposure:** `show_in_rest: true`; REST `POST /extrachill/v1/admin/team-members/sync`.
- **Side effects:** Grants team roles across sites for current team users.

### `extrachill/manage-team-member`

- **Purpose:** Grant or revoke the extra_chill_team WordPress role for a user, network-wide.
- **Input:** `user_id` (integer, **required**); `action` (string; enum ["force_add", "force_remove"], **required**).
- **Output:** `{message, user_id, is_team_member, sites_added}` or `sites_removed` for removal.
- **Permission:** Allows WP-CLI, or ActionScheduler present after `action_scheduler_before_execute` has fired, or `manage_network_options`.
- **Exposure:** `show_in_rest: true`; REST `POST/PUT/PATCH /extrachill/v1/admin/team-members/{user_id}`.
- **Side effects:** Grants or revokes the network-wide team role.

### `extrachill/get-team-experience-stats`

- **Purpose:** Team cohort rollup: current extra_chill_team count, members added/removed in window, and Studio/MCP/submission event counts.
- **Input:** `days` (integer; default 28, optional).
- **Output:** `{days, period, team_member_count, team_members_added, team_members_removed, team_login_activity, events, mcp_usage}`. MCP event counts come from the MCP usage reader; other counts use the canonical analytics event group.
- **Permission:** Requires `manage_options` or WP-CLI.
- **Exposure:** `show_in_rest: false`; CLI `wp extrachill users team stats`.

## Settings and profiles

### `extrachill/get-user-settings`

- **Purpose:** Retrieve private account settings for the authenticated user. See [existing reference](api-endpoints.md).
- **Input:** No declared fields (object input).
- **Output:** `{user_id, first_name, last_name, display_name, display_name_options, email, pending_email, onboarding_completed, local_scene, local_scene_visibility, concert_history_visibility, event_attendance_visibility, local_scene_prompt_dismissed, default_event_location}`.
- **Permission:** Requires a logged-in user. Execute resolves the authenticated self user, ignoring any supplied user ID.
- **Exposure:** `show_in_rest: true`; REST `GET /extrachill/v1/users/me/settings`; CLI `wp extrachill users settings get`.

### `extrachill/update-user-settings`

- **Purpose:** Update account details: first name, last name, display name. See [existing reference](api-endpoints.md).
- **Input:** `first_name` (string, optional); `last_name` (string, optional); `display_name` (string, optional); `local_scene` (string, optional); `local_scene_visibility` (string; enum ["public", "private"], optional); `concert_history_visibility` (string; enum ["public", "private"], optional); `event_attendance_visibility` (string; enum ["public", "private"], optional); `local_scene_prompt_dismissed` (boolean, optional); `default_event_location` (string, optional).
- **Output:** Fresh `get-user-settings` payload; a name-only no-op returns `{success: true, message, user_id}`.
- **Permission:** Requires a logged-in user. Execute resolves the authenticated self user, ignoring any supplied user ID.
- **Exposure:** `show_in_rest: true`; REST `POST /extrachill/v1/users/me/settings`; CLI `wp extrachill users settings update`; CLI `wp extrachill users profile update`.
- **Side effects:** Writes user names and supplied location/privacy/prompt metadata; returns refreshed settings.

### `extrachill/change-user-email`

- **Purpose:** Initiate email change with verification. Sends confirmation to new address. See [existing reference](api-endpoints.md).
- **Input:** `new_email` (string, **required**).
- **Output:** `{success: true, message, pending_email}`.
- **Permission:** Requires a logged-in user. Execute resolves the authenticated self user, ignoring any supplied user ID.
- **Exposure:** `show_in_rest: true`; REST `POST /extrachill/v1/users/me/email`; CLI `wp extrachill users settings change-email`.
- **Side effects:** Stores `_new_email` pending verification metadata and sends confirmation to the new address; removes pending metadata if delivery fails. Does not immediately replace the account email.

### `extrachill/change-user-password`

- **Purpose:** Change user password. Requires current password verification. See [existing reference](api-endpoints.md).
- **Input:** `current_password` (string, **required**); `new_password` (string; minLength 8, **required**); `confirm_password` (string; minLength 8, **required**).
- **Output:** `{success: true, message}`.
- **Permission:** Requires a logged-in user. Execute resolves the authenticated self user, ignoring any supplied user ID.
- **Exposure:** `show_in_rest: true`; REST `POST /extrachill/v1/users/me/password`; CLI `wp extrachill users settings change-password`.
- **Side effects:** Verifies current password and password policy, then updates the WordPress password.

### `extrachill/get-user-profile`

- **Purpose:** Retrieve public profile fields: avatar, title, bio, city, links, artist status. See [existing reference](profiles.md).
- **Input:** `user_id` (integer, **required**).
- **Output:** `{user_id, display_name, username, avatar_url, custom_title, bio, local_city, local_scene, links, link_types, artist_access}`; private scene/city are suppressed.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy.
- **Exposure:** `show_in_rest: true`; REST `GET /extrachill/v1/users/{id}`, `GET /extrachill/v1/users/me/profile`; CLI `wp extrachill users profile get`.

### `extrachill/update-user-profile`

- **Purpose:** Update profile fields: custom title, bio (description), local city. See [existing reference](profiles.md).
- **Input:** `custom_title` (string, optional); `bio` (string, optional); `local_city` (string, optional).
- **Output:** Fresh `get-user-profile` payload, or `{success: true, message, user_id}` on no change.
- **Permission:** Requires a logged-in user. Execute resolves the authenticated self user, ignoring any supplied user ID.
- **Exposure:** `show_in_rest: true`; REST `POST /extrachill/v1/users/me/profile`; CLI `wp extrachill users profile update`.
- **Side effects:** Writes custom title/local-city metadata and/or WordPress description.

### `extrachill/update-user-links`

- **Purpose:** Update user profile social/music links. Replaces all existing links. See [existing reference](profiles.md).
- **Input:** `links` (array of object {type_key: string, url: string, custom_label: string}, **required**).
- **Output:** `{success: true, message, user_id, links}` with normalized links.
- **Permission:** Requires a logged-in user. Execute resolves the authenticated self user, ignoring any supplied user ID.
- **Exposure:** `show_in_rest: true`; REST `POST /extrachill/v1/users/me/links`; CLI `wp extrachill users profile update-links`.
- **Side effects:** Replaces dynamic-link metadata with normalized links, deleting the meta when none remain.

## Discovery and user lookup

### `extrachill/user-event-locations`

- **Purpose:** Search or resolve canonical event markets for the user default event location preference. See [existing reference](api-endpoints.md).
- **Input:** `mode` (string; enum ["search", "resolve"], **required**); `search` (string, optional); `slug` (string, optional); `limit` (integer; default 10; minimum 1; maximum 20, optional).
- **Output:** `object {locations: array of object {term_id: integer, name: string, slug: string, url: string, coordinates: object | null {lat: number, lon: number}, hierarchy: object {region: string, state: string, label: string}}, location: object | null {term_id: integer, name: string, slug: string, url: string, coordinates: object | null {lat: number, lon: number}, hierarchy: object {region: string, state: string, label: string}}}`.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy.
- **Exposure:** `show_in_rest: true`; none found.

### `extrachill/users-leaderboard`

- **Purpose:** Returns ranked users by extrachill_total_points.
- **Input:** `page` (integer; default 1; minimum 1, optional); `per_page` (integer; default 25; minimum 1; maximum 100, optional).
- **Output:** `object {items: array of object {id: integer, display_name: string, username: string, slug: string, avatar_url: string, profile_url: string, registered: string, points: number, rank: string, badges: array, position: integer}, pagination: object {page: integer, per_page: integer, total: integer, total_pages: integer}}`.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy.
- **Exposure:** `show_in_rest: true`; REST `/extrachill/v1/users/leaderboard` (`inc/routes/users/leaderboard.php`); CLI `wp extrachill community leaderboard`.

### `extrachill/users-search`

- **Purpose:** Search users by term. Supports mentions, admin, and artist-capable contexts.
- **Input:** `term` (string, **required**); `context` (string; enum ["mentions", "admin", "artist-capable"]; default "mentions", optional); `exclude_artist_id` (integer, optional).
- **Output:** `array of object {id: integer, display_name: string, username: string, slug: string, email: string, avatar_url: string, profile_url: string}`.
- **Permission:** Requires a logged-in user. Execute requires `manage_options` for admin context, and artist-profile creation eligibility for artist-capable context.
- **Exposure:** `show_in_rest: true`; REST `/extrachill/v1/users/search` (`inc/routes/users/search.php`).

### `extrachill/local-scene-members`

- **Purpose:** Returns public profile cards for members of a canonical Local Scene.
- **Input:** `slug` (string, **required**); `page` (integer; default 1; minimum 1, optional); `per_page` (integer; default 25; minimum 1; maximum 100, optional).
- **Output:** `{scene, members: [{user_id, display_name, username, profile_url, avatar_url, custom_title, bio, rank}], pagination: {page, per_page, total, total_pages}}`.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy.
- **Exposure:** `show_in_rest: true`; none found.

### `extrachill/get-user-by-id`

- **Purpose:** Returns a single user payload by ID. Own profile or admin sees extended fields.
- **Input:** `id` (integer, **required**).
- **Output:** `object {id: integer, display_name: string, username: string, slug: string, avatar_url: string, profile_url: string, is_team_member: boolean, last_active: integer | null, last_login: integer | null, email: string, is_lifetime_member: boolean, is_artist: boolean, is_professional: boolean, can_create_artists: boolean, artist_count: integer, registered: string}`.
- **Permission:** Requires a logged-in user. Only self or `manage_options` receives email, membership/access flags, artist count, registration and last-login fields.
- **Exposure:** `show_in_rest: true`; none found.

### `extrachill/get-user-artists`

- **Purpose:** Returns the artist memberships for a user.
- **Input:** `user_id` (integer, **required**).
- **Output:** `array of object {id: integer, name: string, slug: string, profile_image_url: string | null}` per schema; the callback can also return `false` for an image that no longer resolves.
- **Permission:** Requires a logged-in user. Execute restricts the target to self or `manage_options`.
- **Exposure:** `show_in_rest: true`; none found.

## Entity subscriptions

### `extrachill/entity-subscribe`

- **Purpose:** Subscribe the authenticated user to a canonical entity’s updates. See [existing reference](entity-subscriptions.md).
- **Input:** `entity_type` (string, **required**); `taxonomy` (string, **required**); `slug` (string, **required**).
- **Output:** `{entity_type, taxonomy, slug, subscribed: true}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; none found.
- **Side effects:** Adds or reactivates the authenticated user’s canonical entity subscription.

### `extrachill/entity-unsubscribe`

- **Purpose:** Unsubscribe the authenticated user from a canonical entity’s updates. See [existing reference](entity-subscriptions.md).
- **Input:** `entity_type` (string, **required**); `taxonomy` (string, **required**); `slug` (string, **required**).
- **Output:** `{entity_type, taxonomy, slug, subscribed: false}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; none found.
- **Side effects:** Deactivates the authenticated user’s canonical entity subscription.

### `extrachill/entity-subscription-status`

- **Purpose:** Read the authenticated user’s canonical entity subscription status. See [existing reference](entity-subscriptions.md).
- **Input:** `entity_type` (string, **required**); `taxonomy` (string, **required**); `slug` (string, **required**).
- **Output:** `{entity_type, taxonomy, slug, subscribed: boolean}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; none found.

### `extrachill/list-entity-subscriptions`

- **Purpose:** List the authenticated user’s private canonical entity subscription identities. See [existing reference](entity-subscriptions.md).
- **Input:** `page` (integer, optional); `per_page` (integer, optional).
- **Output:** `{subscriptions: [{entity_type, taxonomy, slug}], page, per_page, total, total_pages}`.
- **Permission:** Requires a logged-in user. Execute resolves the authenticated self user, ignoring any supplied user ID.
- **Exposure:** `show_in_rest: true`; none found.

### `extrachill/resolve-entity-subscription-recipients`

- **Purpose:** Resolve private recipient IDs for an authorized entity-update producer. See [existing reference](entity-subscriptions.md).
- **Input:** `entity_type` (string, **required**); `taxonomy` (string, **required**); `slug` (string, **required**); `producer` (string, **required**); `delivery` (string, optional).
- **Output:** `{recipient_ids: integer[]}`.
- **Permission:** Requires `manage_network_options` (no CLI bypass). Producer authorization is additionally enforced by the private service.
- **Exposure:** `show_in_rest: false`; none found.

## Concert tracking

### `extrachill/set-event-mark`

- **Purpose:** Idempotently mark or unmark an event for the current user, or for another user when authorized.
- **Input:** `user_id` (integer; default 0, optional); `event_id` (integer, **required**); `blog_id` (integer; default 0, optional); `marked` (boolean, **required**); `source` (string; enum ["button", "intent"]; default "button", optional).
- **Output:** `object {user_id: integer, marked: boolean, changed: boolean, count: integer, count_label: string, timing: string, first_mark: boolean}`.
- **Permission:** Requires login and a target that is the current user, unless the caller has `manage_network_options`.
- **Exposure:** `show_in_rest: true`; CLI `wp extrachill shows mark`, `wp extrachill shows unmark`, `wp extrachill shows import`.
- **Side effects:** Idempotently writes/removes attendance; records changed transitions and first-mark analytics/hooks.

### `extrachill/toggle-event-mark`

- **Purpose:** Mark or unmark an event for the current user. Returns new state and attendee count. See [API reference](../../extrachill-api/docs/routes/concert-tracking/tracking.md).
- **Input:** `event_id` (integer, **required**); `blog_id` (integer; default 0, optional).
- **Output:** `object {marked: boolean, count: integer, count_label: string, timing: string}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; REST `POST /extrachill/v1/concert-tracking/toggle`.
- **Side effects:** Toggles the current user’s attendance row through the concert tracking service.

### `extrachill/get-user-shows`

- **Purpose:** Get paginated concert history for a user with enriched event details. Public non-owner reads expose past shows only.
- **Input:** `user_id` (integer; default 0, optional); `period` (string; enum ["upcoming", "past", "all"]; default "all", optional); `year` (integer; default 0, optional); `date_from` (string; default "", optional); `date_to` (string; default "", optional); `page` (integer; default 1, optional); `per_page` (integer; default 20; minimum 1; maximum 100, optional).
- **Output:** `object {shows: array, total: integer, pages: integer, page: integer}`.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy. Execute enforces concert-history visibility; non-owner public reads are past-only.
- **Exposure:** `show_in_rest: true`; REST `GET /extrachill/v1/concert-tracking/user/{user_id}/shows`; CLI `wp extrachill shows list`.

### `extrachill/get-user-concert-stats`

- **Purpose:** Get aggregate concert stats: total shows, unique venues/artists/cities, top lists, shows by year. See [API reference](../../extrachill-api/docs/routes/concert-tracking/tracking.md).
- **Input:** `user_id` (integer; default 0, optional); `year` (integer; default 0, optional); `date_from` (string; default "", optional); `date_to` (string; default "", optional).
- **Output:** `object {total_shows: integer, unique_venues: integer, unique_artists: integer, unique_cities: integer, top_artists: array, top_venues: array, top_cities: array, shows_by_year: object}`.
- **Permission:** Always allows (`__return_true`); no authentication or capability check in the permission callback. Trusted callers must enforce their own admission policy. Execute enforces concert-history visibility; non-owner public reads are past-only.
- **Exposure:** `show_in_rest: true`; REST `GET /extrachill/v1/concert-tracking/user/{user_id}/stats`; CLI `wp extrachill shows stats`.

### `extrachill/search-events-for-marking`

- **Purpose:** Search events by title, artist, or venue, scoped to a period: past (default), upcoming (not yet ended), or all. Returns is_marked per event for the current user. Powers the My Shows quick-add search on both the Upcoming and Past tabs.
- **Input:** `query` (string; default "", optional); `period` (string; enum ["past", "upcoming", "all"]; default "past", optional); `page` (integer; default 1, optional); `per_page` (integer; default 20, optional).
- **Output:** `object {events: array, total: integer, pages: integer, page: integer}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; REST `GET /extrachill/v1/concert-tracking/search`.

### `extrachill/get-event-attendance`

- **Purpose:** Get attendance count and attendee list for an event.
- **Input:** `user_id` (integer; default 0, optional); `event_id` (integer, **required**); `blog_id` (integer; default 0, optional); `include_attendees` (boolean; default false, optional); `limit` (integer; default 10; minimum 1; maximum 100, optional).
- **Output:** `object {count: integer, count_label: string, timing: string, user_marked: boolean, attendees: array of object {user_id: integer, display_name: string, avatar_url: string, profile_url: string}}`.
- **Permission:** Allows requests without an explicit nonzero `user_id`; explicit targets require login and self or `manage_network_options`.
- **Exposure:** `show_in_rest: true`; REST `GET /extrachill/v1/concert-tracking/event/{event_id}`; CLI `wp extrachill shows check`, `wp extrachill shows event`, `wp extrachill shows import`.

### `extrachill/get-event-attendees-full`

- **Purpose:** Get EVERY attendee of an event, including private ones, for an authorized host. Denies by default — see extrachill_users_can_manage_event_attendance_full().
- **Input:** `event_id` (integer, **required**); `blog_id` (integer; default 0, optional); `limit` (integer; default 200; minimum 1; maximum 1000, optional).
- **Output:** `object {attendees: array of object {user_id: integer, display_name: string, avatar_url: string, profile_url: string, marked_at: string}}`.
- **Permission:** Allows `manage_network_options`; otherwise requires login, a positive event ID, and a true `extrachill_users_can_manage_event_attendance` filter result (default false).
- **Exposure:** `show_in_rest: true`; none found.

### `extrachill/rate-show`

- **Purpose:** Set or clear the current user's private 1–5 rating of a past show they attended. Ratings are personal and never shown publicly.
- **Input:** `event_id` (integer; minimum 1, **required**); `rating` (integer; minimum 0; maximum 5, **required**); `blog_id` (integer; default 0, optional).
- **Output:** `object {event_id: integer, rating: integer}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; none found.
- **Side effects:** Sets or clears the authenticated user’s private show rating.

### `extrachill/get-my-show-ratings`

- **Purpose:** The current user's own private show ratings for a set of events. Only rated shows are returned.
- **Input:** `event_ids` (array of integer; maxItems 100, **required**); `blog_id` (integer; default 0, optional).
- **Output:** `object {ratings: object (values: integer)}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; none found.

## Concert imports

### `extrachill/list-concert-import-sources`

- **Purpose:** List all registered concert-history import sources and the current user's saved username for each.
- **Input:** `include_unconfigured` (boolean; default false, optional).
- **Output:** `object {sources: array}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; REST `GET /extrachill/v1/concert-import/sources`.

### `extrachill/preview-concert-import`

- **Purpose:** Validate the username against the external source and return the total event count so the user can confirm before kicking off the full import.
- **Input:** `source` (string, **required**); `username` (string, **required**).
- **Output:** `object {source: string, username: string, total: integer}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; REST `POST /extrachill/v1/concert-import/preview`.
- **Side effects:** Calls the selected external source to validate the username and fetch its event count.

### `extrachill/start-concert-import`

- **Purpose:** Kick off a background import run for the current user from a registered source.
- **Input:** `source` (string, **required**); `username` (string, **required**).
- **Output:** `object {run_id: integer}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; REST `POST /extrachill/v1/concert-import/start`.
- **Side effects:** Creates/queues a background import run, saving source state; background work fetches external history and imports attendance.

### `extrachill/get-concert-import-status`

- **Purpose:** Return the current user's recent import runs with progress + summary counters.
- **Input:** `limit` (integer; default 20, optional).
- **Output:** `object {runs: array}`.
- **Permission:** Requires a logged-in user.
- **Exposure:** `show_in_rest: true`; REST `GET /extrachill/v1/concert-import/status`.

## Notifications

### `extrachill/get-notifications`

- **Purpose:** List notifications for a user (newest first), with optional unread filter and pagination. Reads the network notification table. See [existing reference](notifications.md).
- **Input:** `user_id` (integer; default 0, optional); `unread` (boolean; default false, optional); `page` (integer; default 1, optional); `per_page` (integer; default 50, optional).
- **Output:** `object {user_id: integer, total: integer, unread_count: integer, page: integer, pages: integer, notifications: array}`.
- **Permission:** Requires a logged-in user. Execute resolves notification-user authorization; explicit other-user reads/writes require `list_users`.
- **Exposure:** `show_in_rest: true`; REST `GET /extrachill/v1/community/notifications`; CLI `wp extrachill community notifications`.

### `extrachill/get-notification-unread-count`

- **Purpose:** Return the number of unread notifications for a user. Powers the bell badge. See [existing reference](notifications.md).
- **Input:** `user_id` (integer; default 0, optional).
- **Output:** `object {user_id: integer, unread_count: integer}`.
- **Permission:** Requires a logged-in user. Execute resolves notification-user authorization; explicit other-user reads/writes require `list_users`.
- **Exposure:** `show_in_rest: true`; none found.

### `extrachill/mark-notifications-read`

- **Purpose:** Mark a single notification (by id) or all unread notifications as read for a user. See [existing reference](notifications.md).
- **Input:** `user_id` (integer; default 0, optional); `notification_id` (integer; default 0, optional).
- **Output:** `object {user_id: integer, marked: integer}`.
- **Permission:** Requires a logged-in user. Execute resolves notification-user authorization; explicit other-user reads/writes require `list_users`.
- **Exposure:** `show_in_rest: true`; REST `POST /extrachill/v1/community/notifications/mark-read`; CLI `wp extrachill community notifications`.
- **Side effects:** Updates one notification or all unread notifications for the resolved user.

### `extrachill/clear-notifications`

- **Purpose:** Delete read notifications older than one week for a user, or all notifications. See [existing reference](notifications.md).
- **Input:** `user_id` (integer; default 0, optional); `all` (boolean; default false, optional).
- **Output:** `object {user_id: integer, removed: integer}`.
- **Permission:** Requires a logged-in user. Execute resolves notification-user authorization; explicit other-user reads/writes require `list_users`.
- **Exposure:** `show_in_rest: true`; CLI `wp extrachill community notifications`.
- **Side effects:** Deletes read notifications older than one week by default, or all notifications when `all` is true.

### `extrachill/get-notification-preferences`

- **Purpose:** Return a user's notification delivery preferences (digest emails, push notifications, reply auto-subscribe). Self-only. See [existing reference](notifications.md).
- **Input:** No declared fields (object input).
- **Output:** `object {user_id: integer, emails_enabled: boolean, auto_subscribe_replies: boolean, push_enabled: boolean}`.
- **Permission:** Requires a logged-in user. Execute resolves the authenticated self user, ignoring any supplied user ID.
- **Exposure:** `show_in_rest: true`; none found.

### `extrachill/update-notification-preferences`

- **Purpose:** Update a user's notification delivery preferences: digest emails, push notifications and reply auto-subscribe. Self-only. See [existing reference](notifications.md).
- **Input:** `emails_enabled` (boolean, optional); `auto_subscribe_replies` (boolean, optional); `push_enabled` (boolean, optional).
- **Output:** `object {user_id: integer, emails_enabled: boolean, auto_subscribe_replies: boolean, push_enabled: boolean}`.
- **Permission:** Requires a logged-in user. Execute resolves the authenticated self user, ignoring any supplied user ID.
- **Exposure:** `show_in_rest: true`; none found.
- **Side effects:** Writes digest-email, reply auto-subscribe and/or push opt-out metadata.
