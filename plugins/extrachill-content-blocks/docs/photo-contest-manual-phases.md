# Manual contest closes

Moderators with `edit_others_posts` can use **Close entries now** and **Close voting now** on the contest page (including focused entry pages). Each action asks for confirmation. **Reopen** removes that phase's override; it does not bypass scheduled opening or closing times.

Manual timestamps live in contest-post meta, with independent keys for each contest ID and phase. Block attributes and post content are unchanged. The earlier of the manual timestamp and scheduled close is enforced by the shared settings path for entries and votes. Closing voting also activates the existing finalists display when enabled.

The page reads live phase data from `extrachill/photo-contest-list`, independent of cached HTML, and refreshes every 15 seconds. Writes use the same moderator permission and nonce-backed Abilities REST pattern as hide/unhide.

## Ability

`extrachill/photo-contest-set-phase` takes:

```json
{"post_id":123,"contest_id":"contest-example","phase":"voting","closed":true}
```

`phase` is `entries` or `voting`; `closed: false` clears the override. Repeated closes preserve the original timestamp.

## PR follow-up

Add the corresponding `wp extrachill content photo-contest` close/reopen command in **extrachill-cli**, as a thin adapter over this ability. That command is intentionally not implemented in this repository.
