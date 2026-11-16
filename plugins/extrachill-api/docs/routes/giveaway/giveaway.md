# Giveaways

Paths are relative to `/wp-json/extrachill/v1`. Source: `inc/routes/giveaway/giveaway.php`. Both routes require `current_user_can('edit_posts')` through a permission closure.

## POST /giveaway/run

**Parameters:** required `media_input` string (Instagram URL, shortcode or numeric media ID); optional `entry_mode` (`per_comment` or `per_user`, omitted to use owner default), `require_tag` boolean (true), `min_tags` integer (1, `absint`), `winner_count` integer (1, `absint`), `announce` boolean (false), `message` string (textarea-sanitized; default `Congratulations @{username}, you won the giveaway! Check your DMs for details.`).

**Owner/ability:** Studio ability `extrachill/run-giveaway`.

**Response:** `{winners: [...], stats: {...}}` returned unchanged (200). Missing ability returns `ability_missing` (500); ability errors propagate. Entry mode is forwarded only when supplied.

## POST /giveaway/schedule

**Parameters:** same as run, except `announce` defaults to **true**; additionally required `run_at` string (future UTC datetime, described as ISO 8601). The callback parses with `strtotime` and rejects invalid/nonfuture values.

**Delegation:** Data Machine `Engine\Tasks\TaskScheduler::schedule`, task type `giveaway`, using the giveaway ability input plus metadata for current `user_id`, origin `studio_api` and `scheduled_at`. This route does **not** execute `extrachill/run-giveaway` immediately.

**Response:** `{job_id, task_type: "giveaway", scheduled_at, params}` (200). Missing scheduler: `task_system_missing` (500); bad run time: `invalid_run_at` (400); failed scheduling: `schedule_failed` (500).
