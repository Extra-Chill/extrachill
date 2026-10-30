# Email Analytics Privacy

Email delivery tracking stores short-lived operational outcomes only. New `email_sent` rows contain `recipient_count` and a bounded `context`; new `email_failed` rows additionally contain a sanitized `error_code`. Recipient addresses, subjects, message bodies, headers, attachments, provider payloads, credentials, and free-form failure messages are not stored.

Email outcome rows are retained for 30 days. Because the events table is network-wide, only the network's main site schedules `extrachill_analytics_email_cleanup`. Each invocation deletes at most 1,000 expired rows, removes legacy JSON keys from at most 1,000 valid rows, and deletes at most 1,000 invalid legacy rows. Cleanup acquires a MySQL/MariaDB advisory lock scoped by network ID with `GET_LOCK(name, 0)`, so acquisition is atomic and never waits. The same database connection holds the mutex through the bounded batch and releases it with `RELEASE_LOCK()` in `finally`; no option row or cache entry represents lock ownership. A full batch queues a deduplicated continuation one minute later, so a backlog drains through bounded operations rather than waiting for daily runs. Each destructive query captures its own database error immediately. Failures are logged, saved in the `extrachill_analytics_email_cleanup_error` network option, and retried by continuation.

WordPress Core's personal-data callbacks register only on the main site and intentionally cover that user's rows across the entire network-wide table. Exports use a fixed maximum event ID plus ID-cursor pagination in batches of 500, so concurrent retention cannot shift an OFFSET and skip rows. Cursor state lives for at most one hour in a network transient keyed by a salted HMAC of the requested email; no recipient-derived identifier is stored in analytics. Exports include the originating site ID plus only delivery result, timestamp, context, recipient count, and failure code. Erasure deletes network-wide user-linked email outcome rows in shifting-safe batches of 500. Background or anonymous rows have no user linkage and are handled by automatic retention.

After deployment, operators should confirm the daily cron exists and run the cleanup hook once if immediate legacy cleanup is required:

```sh
wp cron event list --fields=hook,next_run_relative | grep extrachill_analytics_email_cleanup
wp cron event run extrachill_analytics_email_cleanup
```

Run manual cleanup from the main site context. Continuations drain the backlog automatically and the network lock prevents overlap. A database backup should follow the site's normal operational policy before manually accelerating destructive cleanup.
