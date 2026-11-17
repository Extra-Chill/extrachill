# Extra Chill Newsletter abilities

Extra Chill Newsletter registers 13 abilities, all in the `extrachill-newsletter` category (label "Extra Chill Newsletter", registered in `inc/core/abilities/register.php`). They cover:

- public subscription;
- the logged-in member's own preference;
- bulk subscriber sync;
- Sendy campaign drafts, sends and management;
- network newsletter settings;
- subscriber and funnel metrics.

This plugin owns the policy:

- building the email from a `newsletter` post;
- resolving lists from integration contexts;
- validation, caching, and the send guards.

Every Sendy API call is delegated to public `datamachine/sendy-*` abilities from Data Machine Business, which also owns the Sendy credentials (`datamachine_sendy_config`). When a required provider ability is missing, these abilities return a `*_unavailable` `WP_Error`. They never fall back to a local client.

REST routes in `extrachill-api` are documented in [subscription](../../extrachill-api/docs/routes/newsletter/subscription.md), [campaign](../../extrachill-api/docs/routes/newsletter/campaign.md) and [management](../../extrachill-api/docs/routes/newsletter/management.md). The subscription contexts are described in [integrations.md](integrations.md), and the REST contracts in [api-reference.md](api-reference.md). CLI commands live under `wp extrachill newsletter`.

## Subscriptions

### `extrachill/subscribe`

- **Purpose:** Subscribes an email address to a Sendy list. This is the canonical subscription primitive. Contact-form opt-ins, registration auto-subscribe and `extrachill/sync-subscribers` all go through it.
- **Input:**
  - `email` (string, required).
  - `context` (integration key such as `homepage`, `navigation`, `content`, `archive` or `contact`, used to look up the list ID).
  - `list_id` (direct Sendy list ID; skips the context lookup).
  - `name`.
  - `source_url`.
  - One of `list_id` or `context` is required.
- **Output:** `{ success, message, status }`, where `status` is one of `subscribed`, `already_subscribed`, `invalid`, `error` or `failed`.
  - Implausible emails return `success: false` with status `invalid` rather than an error. That covers addresses that sanitization changes and RFC 2606 or `test.*` placeholder domains.
  - Errors: `missing_email`, `missing_list_id`, `invalid_context` or `list_not_configured`.
- **Permission:** `__return_true` (public). The REST route adds Cloudflare Turnstile verification in front of it.
- **Exposure:** `show_in_rest` false.
  - REST: `POST /wp-json/extrachill/v1/newsletter/subscribe` (see [route docs](../../extrachill-api/docs/routes/newsletter/subscription.md)).
  - WP-CLI: `wp extrachill newsletter subscribe`.
  - Also called by `extrachill-contact` and by the network subscribe helpers in `inc/core/sendy-api.php`.
- **Side effects:**
  - Adds the address to a Sendy list through `datamachine/sendy-subscribe`.
  - On success, fires the `extrachill_newsletter_subscribed` action.
  - Records a `newsletter_signup` event through `extrachill/track-analytics-event`, except for the `registration` context. The event is marked `is_bot: false` for real-context HTTP submissions.

### `extrachill/newsletter-get-my-subscriptions`

- **Purpose:** Reports whether the current account's email receives the Extra Chill newsletter (subscribed on any configured network list). Also covered in [integrations.md](integrations.md#member-preferences-and-account-deletion).
- **Input:** none. The input must be an empty object; extra fields such as an email or user ID are rejected.
- **Output:** `{ lists: [ { key: "newsletter", name: "Extra Chill newsletter", subscribed } ] }`, or `{ lists: [] }` when no lists are configured.
  - Errors:
    - `newsletter_login_required` (401).
    - `newsletter_invalid_input` (400).
    - `sendy_unavailable`.
    - `sendy_status_failed`, or an upstream Sendy error.
- **Permission:** `is_user_logged_in`. The callback checks again for direct PHP callers.
- **Exposure:** `show_in_rest` true (through core `/wp-abilities/v1`). No `extrachill-api` route or `wp extrachill` command found.

### `extrachill/newsletter-set-my-subscription`

- **Purpose:** Turns the newsletter on or off for the current account's email.
- **Input:** `list` (string, required; must be `newsletter`) and `subscribed` (strict boolean, required). No other fields are accepted.
- **Output:** `{ list, subscribed }`.
  - Errors:
    - `newsletter_login_required` (401).
    - `newsletter_invalid_input` (400).
    - `newsletter_invalid_list` (400).
    - `newsletter_unconfigured`.
    - `sendy_subscribe_failed`, or an upstream Sendy error.
- **Permission:** `is_user_logged_in`.
- **Exposure:** `show_in_rest` true (through core `/wp-abilities/v1`). No `extrachill-api` route or `wp extrachill` command found.
- **Side effects:**
  - On: subscribes the account email to the `registration` list, or to the `homepage` list if no registration list is configured.
  - Off: unsubscribes the email from every configured network list in Sendy.

### `extrachill/sync-subscribers`

- **Purpose:** Bulk-subscribes addresses to the list behind an integration context. The addresses are either an explicit list or every user registered after a date.
- **Input:**
  - `context` (string, required).
  - `emails` (string[]).
  - `since` (date string compared to `user_registered`).
  - `dry_run` (boolean, default false).
  - One of `emails` or `since` is required.
- **Output:** `{ synced, already_subscribed, skipped, failed, errors[], total }`. Dry runs add `dry_run: true` and only report `total`.
  - Errors:
    - `missing_context`, `invalid_context` or `list_not_configured`.
    - `no_emails`.
    - `ability_not_available`.
    - `sendy_sync_provider_unavailable`.
- **Permission:** `current_user_can( 'manage_options' )`.
- **Exposure:** `show_in_rest` false.
  - REST: `POST /wp-json/extrachill/v1/newsletter/sync` (see [route docs](../../extrachill-api/docs/routes/newsletter/management.md)).
  - WP-CLI: `wp extrachill newsletter sync`.
- **Side effects:** unless `dry_run` is set, runs `extrachill/subscribe` once per address, which writes to Sendy and has that ability's side effects.

## Campaigns

`push-campaign` and `send-campaign` are independent:

- **`extrachill/push-campaign`** creates or updates a Sendy **draft** campaign and tracks it in `_sendy_campaign_id`.
- **`extrachill/send-campaign`** delivers or schedules a newsletter to lists. Sendy's API cannot send an existing draft, so every send creates a **new** campaign and leaves the pushed draft alone.

### `extrachill/push-campaign`

- **Purpose:** Builds HTML and plain-text email content from a `newsletter` post with `prepare_newsletter_email_content()`. It then creates or updates the matching Sendy campaign draft, using the sender identity and brand from the settings.
- **Input:** `post_id` (integer, required).
- **Output:** `{ success: true, campaign_id, message }`.
  - Errors: `missing_post_id`, `post_not_found` or `invalid_post_type`.
  - Provider failures are prefixed "Push to Sendy failed:" and carry HTTP status 502.
- **Permission:** `current_user_can( 'manage_options' )`.
- **Exposure:** `show_in_rest` false.
  - REST: `POST /wp-json/extrachill/v1/newsletter/campaign/push` (see [route docs](../../extrachill-api/docs/routes/newsletter/campaign.md)).
  - WP-CLI: `wp extrachill newsletter push-campaign`.
  - Also called by the legacy `send_newsletter_campaign_to_sendy()` wrapper and by delegated campaign drafting (`inc/core/delegated-campaign.php`).
- **Side effects:**
  - Creates or updates a Sendy campaign through `datamachine/sendy-push-campaign`.
  - When a new campaign is created, stores its ID in the `_sendy_campaign_id` post meta.
  - Nothing is sent to subscribers.

### `extrachill/send-campaign`

- **Purpose:** Sends a `newsletter` post, or schedules it, to chosen Sendy lists or to every list. **It is a dry run by default.** A real send refuses to repeat a post that was already sent or scheduled unless `force` is set.
- **Input:**
  - `post_id` (integer, required).
  - Targets: either `list_ids` (encrypted Sendy list IDs) or `all_lists` (boolean). Exactly one is required.
  - `exclude_list_ids`.
  - `schedule_date_time` (`Y-m-d H:i`, minutes in multiples of 5; omit to send now).
  - `schedule_timezone` (IANA name, default `America/New_York`).
  - `dry_run` (default true).
  - `force`.
  - No extra properties are allowed.
- **Output:** `{ success, dry_run, sent, scheduled, post_id, subject, from_name, from_email, lists[{id,name,active}], excluded, total_active, schedule, previous, message }`.
  - `total_active` is summed per list, before Sendy removes duplicate addresses.
  - Errors:
    - `missing_post_id`, `post_not_found` or `invalid_post_type`.
    - `invalid_post_status`: only `publish` and `draft` can be sent.
    - `empty_newsletter`.
    - `invalid_schedule`.
    - `invalid_targets`, `unknown_list_ids`, `no_target_lists` or `no_sendy_lists`.
    - `newsletter_already_sent`.
    - `newsletter_send_failed`.
- **Permission:** `current_user_can( 'manage_options' )`.
- **Exposure:** `show_in_rest` false. WP-CLI: `wp extrachill newsletter send-campaign`. No `extrachill-api` route found.
- **Side effects:**
  - Always: resolves targets by calling `extrachill/newsletter-subscriber-stats` with `force_refresh` and `source: api`, which refreshes that cache.
  - Real sends only (when `dry_run` is false):
    - creates a campaign through `datamachine/sendy-send-campaign` and emails the targeted subscribers, or schedules the email;
    - writes the `_sendy_sent_at` or `_sendy_scheduled_for`, `_sendy_sent_list_ids` and `_sendy_sent_campaign_id` post meta.

### `extrachill/list-campaigns`

- **Purpose:** Lists Sendy campaigns with status, recipient counts and dates.
- **Input:** `per_page` (default 20), `offset` (default 0), `status` (`sent`, `draft` or `scheduled`).
- **Output:** the campaign list returned by `datamachine/sendy-list-campaigns`, or `sendy_campaign_provider_unavailable`.
- **Permission:** `current_user_can( 'manage_options' )`.
- **Exposure:** `show_in_rest` false.
  - REST: `GET /wp-json/extrachill/v1/newsletter/campaigns` (see [route docs](../../extrachill-api/docs/routes/newsletter/management.md)).
  - WP-CLI: `wp extrachill newsletter campaigns`, which calls the execute callback directly.

### `extrachill/get-campaign`

- **Purpose:** Returns the details of one Sendy campaign.
- **Input:** `campaign_id` (integer, required).
- **Output:** the campaign details returned by `datamachine/sendy-get-campaign`. Errors: `missing_campaign_id` or `sendy_campaign_provider_unavailable`.
- **Permission:** `current_user_can( 'manage_options' )`.
- **Exposure:** `show_in_rest` false.
  - REST: `GET /wp-json/extrachill/v1/newsletter/campaigns/{id}`.
  - WP-CLI: `wp extrachill newsletter campaign <id>`, which calls the callback directly.

### `extrachill/delete-campaign`

- **Purpose:** Deletes a Sendy campaign draft. Sent campaigns cannot be deleted.
- **Input:** `campaign_id` (integer, required).
- **Output:** `{ success, message }` from `datamachine/sendy-delete-campaign`. Errors: `missing_campaign_id` or `sendy_campaign_provider_unavailable`.
- **Permission:** `current_user_can( 'manage_options' )`.
- **Exposure:** `show_in_rest` false.
  - REST: `DELETE /wp-json/extrachill/v1/newsletter/campaigns/{id}`.
  - WP-CLI: `wp extrachill newsletter delete-campaign`, which calls the callback directly.
- **Side effects:** permanently deletes the campaign in Sendy (annotated destructive).

## Settings

### `extrachill/get-newsletter-settings`

- **Purpose:** Returns the network newsletter settings together with each integration's list status and the Sendy connection status.
- **Input:** none.
- **Output:** an object with four fields:
  - `settings` (`from_name`, `from_email`, `reply_to`, `brand_id`);
  - `sendy` (connection status only, never credentials);
  - `integrations` (`{ <context>: { label, list_id, list_id_set } }`);
  - `warnings[]` (integrations missing a list ID, or an unconfigured Sendy connection).
- **Permission:** `current_user_can( 'manage_options' )`.
- **Exposure:** `show_in_rest` false.
  - REST: `GET /wp-json/extrachill/v1/newsletter/settings`.
  - WP-CLI: `wp extrachill newsletter settings`.
  - Also used by the network settings screen (`inc/core/newsletter-settings.php`).

### `extrachill/update-newsletter-settings`

- **Purpose:** Partially updates the network newsletter settings.
- **Input:** any of `from_name`, `from_email`, `reply_to`, `brand_id`, and `list_ids` (an object mapping context to Sendy list ID; unknown contexts are ignored). Passing `sendy_api_key` or `sendy_url` returns `sendy_connection_not_owned` (400).
- **Output:** the same shape as `extrachill/get-newsletter-settings`.
- **Permission:** `current_user_can( 'manage_options' )`.
- **Exposure:** `show_in_rest` false. REST: `POST /wp-json/extrachill/v1/newsletter/settings`. No `wp extrachill` command found.
- **Side effects:** writes the `extrachill_newsletter_settings` network option. Emails are sanitized with `sanitize_email`; other values with `sanitize_text_field`.

## Metrics

### `extrachill/newsletter-subscriber-stats`

- **Purpose:** Returns active subscriber counts across every Sendy list. Active means confirmed, and not unsubscribed, bounced or complained.
- **Input:** `force_refresh` (boolean) and `source` (`auto` | `db` | `api`, default `auto`).
- **Output:** `{ total_active, list_count, lists[{id,name,active}], source, cached, generated_at }`. List `id` values are integer database keys on the `db` path and encrypted IDs on the `api` path. Error: `sendy_primitive_unavailable`.
- **Permission:** `current_user_can( 'manage_options' )`.
- **Exposure:** `show_in_rest` true.
  - REST: `GET /wp-json/extrachill/v1/newsletter/subscribers` is **public**. It calls the execute callback directly, bypassing the ability permission. Only admins can use `refresh` and `source`.
  - WP-CLI: `wp extrachill newsletter subscribers`.
  - Also used internally by `extrachill/send-campaign`.
- **Side effects:** none beyond caching. The result is stored in the `extrachill_newsletter_subscriber_stats` transient for one hour.

### `extrachill/newsletter-funnel-metrics`

- **Purpose:** Reports the email funnel:
  - per-list active subscribers and unsubscribe rate;
  - per-campaign open and click rates;
  - aggregate engagement.
- **Input:** `force_refresh` (boolean) and `campaign_limit` (integer, default 25).
- **Output:** the `datamachine/sendy-metrics` `funnel` payload plus `cached`. Error: `sendy_primitive_unavailable`.
- **Permission:** `current_user_can( 'manage_options' )`.
- **Exposure:** `show_in_rest` true. No `extrachill-api` route or `wp extrachill` command found.
- **Side effects:** none beyond caching. The result is stored in the `extrachill_newsletter_funnel_metrics` transient for one hour.

## Notes

- `extrachill/subscriber-status` was removed from this plugin, and `tests/sendy-campaign-consumer-smoke.php` asserts it is no longer registered. The `extrachill-api` `GET /newsletter/subscriber-status` route and the `wp extrachill newsletter subscriber-status` command that wrapped it were deleted too. `extrachill/newsletter-get-my-subscriptions` is not a replacement: it only reports the current account's own aggregate newsletter state and accepts no email or list ID.
