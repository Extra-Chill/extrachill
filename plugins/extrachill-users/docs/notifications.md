# Notification Delivery

`ec_users_notify_with_receipts()` is the only notification insertion API. Every production producer supplies a stable `producer` namespace and a producer-owned `idempotency_key`. The network-wide unique receipt is scoped by producer, key, and recipient.

The result contains one recipient status:

- `inserted`: this call created the notification.
- `existing`: the same producer/key contract already created it; treat this as successful delivery.
- `failed`: no verified notification exists for this recipient; apply the producer's retry policy with the same key.

Required payload fields are `actor_id`, `type`, `title`, and `link`; `item_id` is optional. Producers that own email delivery must also set `producer_owns_email` and follow the receipt release contract documented on `ec_users_release_notification_receipt()`.

## Users Producers

| Producer | Idempotency key | Outcome policy |
|----------|-----------------|----------------|
| `extrachill-users.concert.show-reminder` | `user:{user}:event:{event}:blog:{blog}` | The scheduled callback accepts inserted/existing and preserves its prior no-retry behavior on failure. |
| `extrachill-users.concert.milestone` | `user:{user}:count:{count}` | Repeated milestone evaluation converges on one row; the mark operation is not failed by notification failure. |
| `extrachill-users.artist-dispatch` | `request:{request}:event:{event}` | Inserted/existing finalizes the transition delivery ledger. Failed clears the reservation for retry; ambiguous ledger state still requires reconciliation. |
| `extrachill-users.publish-notify` | `context:{context}:blog:{blog}:post:{post}` | The post guard records every attempted valid recipient, including failed, preserving the observer's attempt-once/no-storm policy. |

Registered publish-notify contexts and blog IDs are part of the key because post IDs are only unique within a site and independent sources may watch the same post.

## Push Channel

`inc/notifications/push.php` delivers genuine inserts to devices through the optional wp-native-auth primitive `wp_native_auth_send_push( int $user_id, array $message ): array`. The substrate fires `ec_users_notification_inserted( $notification_id, $user_id, $payload )` once per `inserted` receipt; `existing` replays and failures never fire it. When wp-native-auth is not active (`function_exists()` is false) the channel is a no-op. Device tokens are owned by wp-native-auth.

- **Allow-list:** `ec_users_notification_push_types()` (filter `ec_users_notification_push_types`) is the single map of types that push: `reply`, `subscription`, `mention`, approvals (`artist_dispatch_approved`, `artist_dispatch_rejected`, `artist_dispatch_published`, `studio_published`, `artist_import_approved`, `artist_import_rejected`, `event_source_approved`) and requested work (`editorial_submission`, `guest_list_request`, booking inquiry/correction/cancellation/reply types). Engagement nudges are never listed.
- **Lock-screen safety:** the title is a generic per-type string (for example "New reply from {actor}"). The stored notification title is used as the body only for `public` scope types, or for `forum` scope items whose post and every ancestor are `publish` and not `_bbp_status = closed`. Private/hidden/closed forums, venue-scoped, booking and review items never carry a body. Override with `ec_users_notification_push_is_private`.
- **Payload:** `data` = `{ notification_id, type, url }`; `collapse_id` is `ec-topic-{topic}` for forum items, otherwise `ec-{type}-{item_id}` (or `ec-notification-{id}`).
- **Preference:** `ec_notification_push_disabled` user meta, written only by `ec_users_set_notification_push_disabled()`; read with `ec_users_notification_push_enabled()`. Exposed as `push_enabled` on `extrachill/get-notification-preferences` and `extrachill/update-notification-preferences`, and as a toggle in the community settings Notifications tab.
