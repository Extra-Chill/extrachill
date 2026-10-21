# Captcha bot reclassification

Analytics listens for `extrachill_turnstile_rejected( $error_code, $request_or_token )` from extrachill-network. Until that action is emitted, the listener is inactive. Neither argument is stored.

A valid existing `ec_vid` cookie (respecting GPC/DNT) identifies the visitor. Two kinds of server-side evidence mark a visitor as a bot on the current blog:

- **`turnstile_rejected`**: the visitor's request was rejected by Turnstile.
- **`solver_signature`**: a `cta_click` event arrives whose `label` contains the literal `ERROR_EMPTY_KEY`. A captcha-solver browser extension injects that text into the submit button; our code never emits it. This catches the large majority of solver bots, whose extension stalls the form so the subscribe request is never sent and Turnstile never runs.

When evidence is recorded:

- The visitor's last 30 minutes of events get `is_bot=true` and `bot_reason=<reason>`. Other payload keys are preserved, and a row that already has a `bot_reason` keeps it.
- The evidence is kept in a 30-minute site transient keyed by blog and visitor. Beacons arriving in **later requests** are stamped at write time, even if the caller supplied `is_bot=false`.
- Explicit evidence also overrides authentication in outcome trust; ordinary heuristic bot flags keep the existing authenticated exception.

No cookie or an opted-out visitor is a no-op.

## Historical solver cleanup

Preview network-wide:

```sh
wp extrachill-analytics backfill-solver-bots
```

Apply on the newsletter blog:

```sh
wp extrachill-analytics backfill-solver-bots --blog=9 --batch-size=2000 --live
```

The WP-CLI-independent equivalent is `extrachill_analytics_backfill_solver_bots( array( 'blog_id' => 9, 'live' => true ) )`. Default mode is dry run; `blog_id=0` scans the network. Results include scanned/stamped counts, batches, mode, and an error on database failure. Runs are idempotent and cursor-batched: rows that already carry a `bot_reason` are skipped, so a second run scans nothing.

Only `cta_click` labels containing the literal `ERROR_EMPTY_KEY` signature identify solver visitors. All their events on that same blog are stamped `is_bot=true` and `bot_reason=solver_signature`, preserving other keys. Generic captcha errors, other payload fields, and visits on other blogs are not matched. This historical operation has no time limit; preview before applying. No schema migration is required.
