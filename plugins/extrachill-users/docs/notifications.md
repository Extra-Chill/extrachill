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
