# Public event submission review

`EventSubmissionApproval` consumes Data Machine pending actions; it does not implement tokens or a public endpoint. It registers with `PendingEventSubmissionsAbility`.

After `datamachine_events_after_event_upsert_persistence` reports success, a pending `data_machine_events` post with submitter email gets one review email. This hook runs after submitter metadata, event dates and venue terms are saved. Other pending events and trusted submissions published immediately do not trigger this email. A post-level notification marker suppresses repeat upserts. The old job-queued admin email is removed: jobs that fail before event persistence no longer email the admin; Data Machine job logs remain the failure diagnostic.

The recipient uses `extrachill_pending_event_submissions_recipient`, falling back to `admin_email`. Mail includes event details, view/edit links, the pending **events** list, and signed Approve/Reject links. Both the staged action and links have a seven-day lifetime. Links are bearer credentials: do not forward the mail to someone who should not review submissions.

Data Machine owns signature verification, scanner-safe GET confirmation, POST resolution and single-use claims. Approval consumes its authorization receipt and calls `wp_update_post`, preserving the existing pending-to-publish submitter notification. Rejection uses the resolved-action observer (the substrate only calls `apply` on acceptance) and `wp_trash_post`; already-resolved events are not modified. If WordPress trash is disabled, rejection logs an error and leaves the event pending rather than permanently deleting it. Expiry changes no post state. Users resolving without a signed link need event edit and publish capabilities; no agent grant is staged.

If signing is unavailable, the review email still supplies wp-admin review links. Mail delivery failures retain the existing dispatch logging behavior; this change does not add a mail retry queue. Daily digests are unchanged.

Verification:

```sh
vendor/bin/phpunit -c phpunit-event-submission-approval-unit.xml.dist
vendor/bin/phpunit -c phpunit-pending-event-submissions-unit.xml.dist
vendor/bin/phpunit -c phpunit.xml.dist
```

The isolated approval suite tests consumer wiring with Data Machine/WordPress doubles. On a coordinated WordPress install, additionally submit an anonymous event, open each link while logged out (GET must not mutate), confirm via POST, verify repeat use is harmless, and check that approval sends the existing submitter publication email.
