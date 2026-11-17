# Current-user account routes

Paths are relative to `/wp-json/extrachill/v1`. All routes below require login through their file's `extrachill_api_user_*_permission` callback; unauthenticated requests return `rest_forbidden` (401). They delegate to `extrachill-users` abilities and return the ability result unchanged (200); ability errors propagate. Missing abilities return `ability_not_found` (500). A client-supplied user ID never selects another account.

## GET /users/me/profile

**Source:** `inc/routes/users/profile.php`. **Parameters:** none.

**Ability:** `extrachill/get-user-profile` with current `user_id`.

**Response:** `{user_id, display_name, username, avatar_url, custom_title, bio, local_city, local_scene, links, link_types, artist_access}`. City/scene respect public visibility; this is not an account-email read.

## POST /users/me/profile

**Source:** `profile.php`. **Parameters:** optional `custom_title` string, `bio` string, `local_city` string. Title/city are text-sanitized; only non-null supplied fields are forwarded alongside current `user_id`.

**Ability:** `extrachill/update-user-profile`. **Response:** fresh profile object, or `{success: true, message, user_id}` when nothing changed.

## POST /users/me/links

**Source:** `profile.php`. **Parameters:** required `links` array of objects with string `type_key`, `url`, optional `custom_label`. The ability requires type and URL and owns URL/type validation. This replaces the entire link collection; it is not an append operation.

**Ability:** `extrachill/update-user-links` with current `user_id`. **Response:** `{success: true, message, user_id, links}` with normalized links.

## GET /users/me/settings

**Source:** `inc/routes/users/settings.php`. **Parameters:** none.

**Ability:** `extrachill/get-user-settings`, executed with empty input (owner resolves the current user). **Response:** `{user_id, first_name, last_name, display_name, display_name_options, email, pending_email, onboarding_completed, local_scene, local_scene_visibility, concert_history_visibility, event_attendance_visibility, local_scene_prompt_dismissed, default_event_location}`.

## POST /users/me/settings

**Source:** `settings.php`. **Parameters:** optional string `first_name`, `last_name`, `display_name`, `local_scene`, `default_event_location`; optional `local_scene_visibility`, `concert_history_visibility`, `event_attendance_visibility`, each `public` or `private`. Location fields use `sanitize_title`; names use `sanitize_text_field`; visibility uses `sanitize_key`. Only supplied non-null fields are forwarded.

**Ability:** `extrachill/update-user-settings`. **Response:** account settings update result. An `ability_invalid_input` error without an HTTP status is assigned 400 by this adapter.

## POST /users/me/email

**Source:** `settings.php`. **Parameters:** required `new_email` string (`sanitize_email`).

**Ability:** `extrachill/change-user-email` with current `user_id`. **Response:** `{success: true, message, pending_email}`. Users sends a verification email; this is not an immediate unverified email replacement.

## POST /users/me/password

**Source:** `settings.php`. **Parameters:** required string `current_password`, `new_password`, `confirm_password`.

**Ability:** `extrachill/change-user-password` with current `user_id`. **Response:** `{success: true, message}`. Password verification, policy and session effects are not implemented by the API adapter.

## Retired: /users/me/subscriptions

`GET` and `POST /users/me/subscriptions` were removed (#726). They wrapped the retired email-sharing abilities `extrachill/get-subscriptions` and `extrachill/update-subscriptions`, so every request returned `ability_not_found`. A member's artist list subscriptions are served by `GET /artists/me/subscriptions` and `DELETE /artists/me/subscriptions/{subscriber_id}` (see [Artist subscribers](../artists/subscribers.md)).

## POST /users/me/artist-access

**Source:** `inc/routes/users/artist-access-request.php`. **Parameters:** required `type` string, `artist` or `professional`.

**Ability:** `extrachill/request-artist-access` with current `user_id`. **Response:** `{success: true, message, user_id, type}`. This requests access; it does not approve it. Approval/rejection uses the separate [admin artist-access routes](../admin/artist-access.md).
