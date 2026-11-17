# Extra Chill Contact abilities

Extra Chill Contact registers one ability, in the `extrachill-contact` category: the contact-form submission pipeline. The contact block's REST route is a thin wrapper around it.

### `extrachill/contact-submit`

- **Purpose:** Processes a contact form submission. It verifies Turnstile, emails the site administrator and the sender, and subscribes the sender to the `contact` newsletter list only when they explicitly opt in.
- **Input:**
  - `name` (string, required): sender name.
  - `email` (string, email, required): sender address.
  - `subject` (string, required).
  - `message` (string, required).
  - `turnstile_response` (string, required): Cloudflare Turnstile token. Verification is skipped when `WP_ENVIRONMENT_TYPE` is `local` or the `extrachill_bypass_turnstile_verification` filter returns true.
  - `source_url` (string, uri, optional): the page that submitted the form. Falls back to the HTTP referer.
  - `newsletter_opt_in` (boolean, optional, default `false`): only an explicit truthy value subscribes the sender.
- **Output:** `{ success, message, submission_id, replayed, side_effects }`.
  - `submission_id` is a SHA-256 hash of the normalized name/email/subject/message. The Turnstile token is not part of the hash.
  - `replayed` is true when a cached result for the same submission is returned.
  - `side_effects` has three entries: `administrator_delivery`, `confirmation_delivery` and `newsletter_sync`. Each one is `{ success, status, provider, optional, retryable, message }`. Statuses include `delivered`, `failed`, `skipped`, `not_opted_in`, `provider_unavailable`, `malformed_provider_response` and the subscribe status.
  - Errors:
    - `invalid_email` (400).
    - `turnstile_missing` (500): the Turnstile helper is not loaded.
    - `turnstile_failed` (403).
    - `submission_in_progress` (409): the same submission is already being processed.
    - `contact_administrator_delivery_failed` (502): carries the failed result under `submission`. When this happens, the confirmation email and newsletter sync are reported as `skipped`.
- **Permission:** `__return_true`, so it is public. Abuse protection comes from Turnstile inside the callback.
- **Exposure:** `show_in_rest` true (annotations: not readonly, idempotent, not destructive). REST: `POST /wp-json/extrachill/v1/contact/submit` (`plugins/extrachill-api/inc/routes/contact/submit.php`; see [route docs](../../extrachill-api/docs/routes/contact/submit.md)). This route does not verify Turnstile itself, because tokens are single-use. No `wp extrachill` command found.
- **Side effects:**
  - Sends the administrator notification to `admin_email` (template `extrachill/minimal`, reply-to set to the sender) through `ec_send_email` / `datamachine/send-email`.
  - Sends the sender a confirmation email (template `extrachill/branded`).
  - On opt-in, calls `extrachill_network_subscribe( $email, 'contact', $source_url, $name )` from Extra Chill Newsletter.
  - Holds a 30-second object-cache lock per submission ID. Results are cached in the `extrachill_contact_<id>` transient: for 1 day on success, or 1 hour after an administrator delivery failure. A cached successful result is returned again without sending more email.
