# Captcha bot reclassification

Analytics listens for `extrachill_turnstile_rejected( $error_code, $request_or_token )` from extrachill-network. Until that action is emitted, the listener is inactive. Neither argument is stored.

A valid existing `ec_vid` cookie (respecting GPC/DNT) identifies the visitor. Rejection stamps `is_bot=true` and `bot_reason=turnstile_rejected` on their last 30 minutes of events on the current blog. Other payload keys are preserved. Subsequent writes in that request are stamped even if the caller supplied `is_bot=false`. Explicit rejection evidence also overrides authentication in outcome trust; ordinary heuristic bot flags retain the existing authenticated exception. No cookie or an opted-out visitor is a no-op.

## Historical solver cleanup

Preview network-wide:

```sh
wp extrachill-analytics backfill-solver-bots
```

Apply on the newsletter blog:

```sh
wp extrachill-analytics backfill-solver-bots --blog=9 --batch-size=2000 --live
```

The WP-CLI-independent equivalent is `extrachill_analytics_backfill_solver_bots( array( 'blog_id' => 9, 'live' => true ) )`. Default mode is dry run; `blog_id=0` scans the network. Results include scanned/stamped counts, batches, mode, and an error on database failure. Runs are idempotent and cursor-batched.

Only `cta_click` labels containing the literal `ERROR_EMPTY_KEY` signature identify solver visitors. All their events on that same blog are stamped `is_bot=true` and `bot_reason=solver_signature`, preserving other keys. Generic captcha errors, other payload fields, and visits on other blogs are not matched. This historical operation has no time limit; preview before applying. No schema migration is required.
